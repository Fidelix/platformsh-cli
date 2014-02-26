<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Guzzle\Plugin\Oauth2\Oauth2Plugin;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\PasswordCredentials;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\RefreshToken;
use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;

class PlatformCommand extends Command
{
    protected $config;
    protected $oauth2Plugin;
    protected $accountClient;
    protected $platformClient;

    /**
     * Load configuration from the user's .platform file.
     *
     * Configuration is loaded only if $this->config hasn't been populated
     * already. This allows LoginCommand to avoid writing the config file
     * before using the client for the first time.
     *
     * @return array The populated configuration array.
     */
    protected function loadConfig()
    {
        if (!$this->config) {
            $homeDir = trim(shell_exec('cd ~ && pwd'));
            $yaml = new Parser();
            $this->config = $yaml->parse(file_get_contents($homeDir . '/.platform'));
        }

        return $this->config;
    }

    /**
     * Return an instance of Oauth2Plugin.
     *
     * @return Oauth2Plugin
     */
    protected function getOauth2Plugin()
    {
        if (!$this->oauth2Plugin) {
            $this->loadConfig();
            if (empty($this->config['refresh_token'])) {
                throw new \Exception('Refresh token not found in PlatformCommand::getOauth2Plugin.');
            }

            $oauth2Client = new Client('https://marketplace.commerceguys.com/oauth2/token');
            $config = array(
                'client_id' => 'platform-cli',
            );
            $refreshTokenGrantType = new RefreshToken($oauth2Client, $config);
            $this->oauth2Plugin = new Oauth2Plugin(null, $refreshTokenGrantType);
            $this->oauth2Plugin->setRefreshToken($this->config['refresh_token']);
            if (!empty($this->config['access_token'])) {
                $this->oauth2Plugin->setAccessToken($this->config['access_token']);
            }
        }

        return $this->oauth2Plugin;
    }

    /**
     * Authenticate the user using the given credentials.
     *
     * The credentials are used to acquire a set of tokens (access token
     * and refresh token) that are then stored and used for all future requests.
     * The actual credentials are never stored, there is no need to reuse them
     * since the refresh token never expires.
     *
     * @param string $email The user's email.
     * @param string $password The user's password.
     */
    protected function authenticateUser($email, $password)
    {
        $oauth2Client = new Client('https://marketplace.commerceguys.com/oauth2/token');
        $config = array(
            'username' => $email,
            'password' => $password,
            'client_id' => 'platform-cli',
        );
        $grantType = new PasswordCredentials($oauth2Client, $config);
        $oauth2Plugin = new Oauth2Plugin($grantType);
        $this->config = array(
            'access_token' => $oauth2Plugin->getAccessToken(),
            'refresh_token' => $oauth2Plugin->getRefreshToken(),
        );
    }

    /**
     * Return an instance of the Guzzle client for the Accounts endpoint.
     *
     * @return Client
     */
    protected function getAccountClient()
    {
        if (!$this->accountClient) {
            $description = ServiceDescription::factory(CLI_ROOT . '/services/accounts.php');
            $oauth2Plugin = $this->getOauth2Plugin();
            $this->accountClient = new Client();
            $this->accountClient->setDescription($description);
            $this->accountClient->addSubscriber($oauth2Plugin);
        }

        return $this->accountClient;
    }

    /**
     * Return an instance of the Guzzle client for the Platform endpoint.
     *
     * @param string $baseUrl The base url for API calls, usually the project URI.
     *
     * @return Client
     */
    protected function getPlatformClient($baseUrl)
    {
        if (!$this->platformClient) {
            $description = ServiceDescription::factory(CLI_ROOT . '/services/platform.php');
            $oauth2Plugin = $this->getOauth2Plugin();
            $this->platformClient = new Client();
            $this->platformClient->setDescription($description);
            $this->platformClient->addSubscriber($oauth2Plugin);
            // Platform doesn't have a valid SSL cert yet.
            // @todo Remove this
            $this->platformClient->setDefaultOption('verify', false);
        }
        // The base url can change between two requests in the same command,
        // so it needs to be explicitly set every time.
        $this->platformClient->setBaseUrl($baseUrl);

        return $this->platformClient;
    }

    /**
     * Return the user's projects.
     *
     * The projects are persisted in config, refreshed in PlatformListCommand.
     * Most platform commands (such as the environment ones) operate on a
     * project, so this persistence allows them to avoid loading the platform
     * list each time.
     *
     * @param boolean $refresh Whether to refetch the list of projects.
     *
     * @return array The user's projects.
     */
    protected function getProjects($refresh = false)
    {
        $this->loadConfig();
        if (empty($this->config['projects']) || $refresh) {
            $accountClient = $this->getAccountClient();
            $data = $accountClient->getProjects();
            // Extract the project id and rekey the array.
            $projects = array();
            foreach ($data['projects'] as $project) {
                $urlParts = explode('/', $project['uri']);
                $id = end($urlParts);
                $project['id'] = $id;
                $projects[$id] = $project;
            }
            $this->config['projects'] = $projects;
        }

        return $this->config['projects'];
    }

    /**
     * Return the user's environments.
     *
     * The environments are persisted in config, refreshed in
     * EnvironmentListCommand. This persistence allows environment commands to
     * avoid loading the environment list each time.
     * Since the previous list is available on refresh, it can be compared,
     * allowing drush aliases to be refreshed accordingly.
     *
     * @param array $project  The project.
     * @param boolean $refresh Whether to refetch the list of projects.
     *
     * @return array The user's environments.
     */
    protected function getEnvironments($project, $refresh = false)
    {
        $this->loadConfig();
        $this->config += array('environments' => array());

        if (empty($this->config['environments']) || $refresh) {
            // Fetch and assemble a list of environments.
            $urlParts = parse_url($project['endpoint']);
            $baseUrl = $urlParts['scheme'] . '://' . $urlParts['host'];
            $client = $this->getPlatformClient($project['endpoint']);
            $environments = array();
            foreach ($client->getEnvironments() as $environment) {
                // The environments endpoint is temporarily not serving
                // absolute urls, so we need to construct one.
                $environment['endpoint'] = $baseUrl . $environment['_links']['self']['href'];
                $environments[$environment['id']] = $environment;
            }
            // Recreate the aliases if the list of environments has changed.
            if (array_diff_key($environments, $this->config['environments'])) {
                $this->createDrushAliases($project, $environments);
            }

            $this->config['environments'] = $environments;
        }

        return $this->config['environments'];
    }

    /**
     * Create drush aliases for the provided project and environments.
     *
     * @param array $project The project
     * @param array $environments The environments
     */
    protected function createDrushAliases($project, $environments)
    {
        if (!$environments) {
            return;
        }

        $aliases = array();
        $export = "<?php\n\n";
        foreach ($environments as $environment) {
            $sshUrl = parse_url($environment['_links']['ssh']['href']);
            $alias = array(
              'parent' => '@parent',
              'site' => $project['id'],
              'env' => $environment['id'],
              'remote-host' => $sshUrl['host'],
              'remote-user' => $sshUrl['user'],
            );
            $export .= "\$aliases['" . $environment['id'] . "'] = " . var_export($alias, TRUE);
            $export .= ";\n";
        }

        $homeDir = trim(shell_exec('cd ~ && pwd'));
        $filename = $homeDir . '/.drush/' . $project['id'] . '.aliases.drushrc.php';
        file_put_contents($filename, $export);
    }

    /**
     * Destructor: Writes the configuration to disk.
     */
    public function __destruct()
    {
        if (is_array($this->config)) {
            if ($this->oauth2Plugin) {
                // Save the access token for future requests.
                $this->config['access_token'] = $this->oauth2Plugin->getAccessToken();
            }

            $dumper = new Dumper();
            $homeDir = trim(shell_exec('cd ~ && pwd'));
            file_put_contents($homeDir . '/.platform', $dumper->dump($this->config));
        }
    }

    /**
     * @return boolean Whether the user has configured the CLI.
     */
    protected function hasConfiguration()
    {
        $homeDir = trim(shell_exec('cd ~ && pwd'));
        return file_exists($homeDir . '/.platform');
    }
}