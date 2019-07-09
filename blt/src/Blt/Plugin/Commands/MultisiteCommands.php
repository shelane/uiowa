<?php

namespace Example\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Common\YamlMunge;
use Acquia\Blt\Robo\Common\YamlWriter;
use Acquia\Blt\Robo\Exceptions\BltException;
use AcquiaCloudApi\CloudApi\Client;
use AcquiaCloudApi\CloudApi\Connector;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandError;
use Grasmash\YamlExpander\Expander;
use Robo\Contract\VerbosityThresholdInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Yaml\Yaml;

define('LOCAL_BASE_DOMAIN', 'local.site');
define('UIOWA_BASE_DOMAIN', 'uiowa.edu');
define('DEFAULT_INSTALL_PROFILE', 'collegiate');

/**
 * Adds commands in the uiowa:* space.
 */
class MultisiteCommands extends BltTasks {

  /**
   * Execute a Drush command against all multisites.
   *
   * @param string $cmd
   *   The simple Drush command to execute, e.g. 'cron' or 'cache:rebuild'. No
   *    support for options or arguments at this time.
   *
   * @command uiowa:multisite:drush
   *
   * @aliases umd uimultidrush
   *
   * @throws \Exception
   */
  public function execute($cmd) {
    if (!$this->confirm("You will execute 'drush {$cmd}' on all multisites. Are you sure?", TRUE)) {
      throw new \Exception('Aborted.');
    }
    else {
      foreach ($this->getConfigValue('multisites') as $multisite) {
        $this->switchSiteContext($multisite);

        $this->taskDrush()
          ->drush($cmd)
          ->run();
      }
    }
  }

  /**
   * Require the --site-uri option so it can be used in postMultisiteInit.
   *
   * @hook validate uiowa:multisite
   */
  public function validateMultisite(CommandData $commandData) {
    $creds = [
      'credentials.acquia.key',
      'credentials.acquia.secret',
    ];

    $errors = [];

    foreach ($creds as $cred) {
      if (!$this->getConfigValue($cred)) {
        $errors[] = "{$cred} is not set in the {$this->getConfigValue('repo.root')}/blt/local.blt.yml file.";
      }
    }

    if (!empty($errors)) {
      $this->io()->listing($errors);
      return new CommandError("The errors listed above need to be corrected prior to running this command.");
    }

  }

  /**
   * Generates a new multisite.
   *
   * @command uiowa:multisite
   *
   * @option string $site-dir
   * @option string $site-uri
   * @option string $machine-name
   * @option string $remote-alias
   * @option string $install-profile
   * @option string $account-mail
   * @option string $pretend
   *
   * @aliases uis uisite
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  public function generate(InputInterface $input) {

    $this->say("This will generate a new site in the docroot/sites directory.");

    $options = $input->getOptions();

    // 1. Get the production domain.
    $domain = $this->getNewSiteDomain($options);

    // 2. Turn the domain into a machine name.
    $machine_name = $this->generateMachineName($options, $domain);
    $this->say('>>> machine name: ' . $machine_name);

    $site_dir = $this->getNewSiteDir($options, $domain);

    // 3. Get and set the site directory.
    $new_site_dir = $this->getConfigValue('docroot') . '/sites/' . $site_dir;
    $this->say('>>> new site dir: ' . $new_site_dir);

    if (file_exists($new_site_dir)) {
      throw new BltException("Cannot generate new multisite, $new_site_dir already exists!");
    }

    $url = parse_url($domain);

    $input->setOption('site-dir', $site_dir);

    $newDBSettings = $this->setLocalDbConfig($site_dir);
    if ($this->getInspector()->isDrupalVmConfigPresent()) {
      $this->configureDrupalVm($url, $newDBSettings);
    }
    $default_site_dir = $this->getConfigValue('docroot') . '/sites/default';
    $this->createDefaultBltSiteYml($default_site_dir);
    // $this->createSiteDrushAlias('default');
    $this->createNewSiteDir($default_site_dir, $new_site_dir);

    $remote_alias = $this->getNewSiteAlias($machine_name, $options, 'remote');
    // Default local alias to self and don't prompt the user.
    $local_alias = 'self';
    $this->createNewBltSiteYml($new_site_dir, $machine_name, $url, $local_alias, $remote_alias, $newDBSettings['database']);
    $this->createNewSiteConfigDir($site_dir);
    $this->createSiteDrushAlias($machine_name, $site_dir);
    $this->resetMultisiteConfig();

    $input->setOption('site-uri', $domain);
    $input->setOption('machine-name', $machine_name);
    $input->setOption('remote-alias', $remote_alias);

    $this->invokeCommand('blt:init:settings');

    $this->say("New site generated at <comment>$new_site_dir</comment>");
    $this->say("Drush aliases generated:");
    if (!file_exists($default_site_dir . "/blt.yml")) {
      $this->say("  * @default.local");
    }
    $this->say("  * @$remote_alias");
    $this->say("Config directory created for new site at <comment>config/$site_dir</comment>");
  }

  /**
   * Removes a multisite from the application.
   *
   * @command uiowa:multisite:remove
   *
   * @option string $site-dir
   *
   * @aliases uir uiremove
   */
  public function remove(InputInterface $input) {
    if ($this->confirm('Are you sure that you want to remove this site? This action cannot be undone.')) {
      // Delete the config/{$site_dir} directory.
      // Delete the docroot/sites/{$site_dir} directory.
      // Remove the alias file.
    }
  }

  /**
   * This will be called after the `uiowa:multisite` command is executed.
   *
   * @hook post-command uiowa:multisite
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   * @throws \Robo\Exception\TaskException
   */
  public function postMultisite($result, CommandData $commandData) {
    if ($result instanceof CommandError) {
      return;
    }
    $this->say('>>> Running post-command hook');
    $input = $commandData->input();
    $site_dir = $input->getOption('site-dir');
    $machine_name = $input->getOption('machine-name');
    $install_profile = $input->getOption('install-profile');
    $options = $input->getOptions();

    $domains = [
      'local' => "{$machine_name}.uiowa.local.site",
      'dev' => "{$machine_name}.dev.drupal.uiowa.edu",
      'test' => "{$machine_name}.stage.drupal.uiowa.edu",
      'prod' => "{$machine_name}.prod.drupal.uiowa.edu",
    ];

    $this->removeExtraFiles($site_dir);

    $this->regenerateDrushAliases($machine_name, $site_dir, $domains);

    // @todo Validate domains, if possible.
    $this->writeToSitesPhpFile($site_dir, $domains);

    // Site install locally so we can do some post-install tasks.
    // @see: https://www.drupal.org/project/drupal/issues/2982052
    $this->switchSiteContext($site_dir);

    $db = $this->getConfigValue('drupal.db');

    $install = $this->installSite($site_dir, $install_profile, $options + ['account-name' => 'admin']);

    $branch = "initialize-{$machine_name}";

    $this->taskGit()
      ->dir($this->getConfigValue("repo.root"))
      ->exec("git checkout -b {$branch}")
      ->add('docroot/sites/sites.php')
      ->commit("Add sites.php entries for {$site_dir}.")
      ->add("docroot/sites/{$site_dir}")
      ->commit("Initialize {$site_dir} site directory.")
      ->exec("git push -u origin {$branch}")
      ->interactive(FALSE)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();

    $connector = new Connector([
      'key' => $this->getConfigValue('credentials.acquia.key'),
      'secret' => $this->getConfigValue('credentials.acquia.secret'),
    ]);

    $cloud = Client::factory($connector);

    $application = $cloud->application($this->getConfigValue('cloud.appId'));
    $cloud->databaseCreate($application->uuid, $db['database']);
    $this->say("Created <comment>{$db['database']}</comment> database.");

    $this->yell("Follow these next steps:");
    $steps = [
      0 => "Open a PR at https://github.com/uiowa/{$this->getConfig()->get('project.prefix')}/compare/master...{$branch}.",
      1 => "Assuming tests pass, merge the PR to deploy to the dev environment.",
    // 3 => "Re-deploy the master branch to the dev environment in the Cloud UI. This will run the cloud hooks successfully.",
    // 4 => "Coordinate a new release to deploy to the test and prod environments.",
      6 => "Add the multisite domains to environments as needed.",
    ];

    if ($install) {
      $steps[2] = "Sync local database and files to dev environment - remember to clear cache locally <comment>first</comment>!";
      $steps[5] = "Sync the database and files to the test and prod environments.";
    }

    ksort($steps);

    $this->io()->listing($steps);
  }

  /**
   * Updates box/config.yml with settings for new multisite.
   *
   * @param array $url
   *   The local URL for the site.
   * @param array $newDBSettings
   *   An array of database configuration options or empty array.
   */
  protected function configureDrupalVm(array $url, array $newDBSettings) {
    $configure_vm = $this->confirm("Would you like to generate new virtual host entry and database for this site inside Drupal VM?");
    if ($configure_vm) {
      $yamlWriter = new YamlWriter($this->getConfigValue('vm.config'));
      $vm_config = $yamlWriter->getContents();
      $vm_config['apache_vhosts'][] = [
        'servername' => $url['host'],
        'documentroot' => $vm_config['apache_vhosts'][0]['documentroot'],
        'extra_parameters' => $vm_config['apache_vhosts'][0]['extra_parameters'],
      ];

      // Set up the database setting for Drupal VM.
      if ($newDBSettings) {
        $vm_config['mysql_databases'][] = [
          'name' => $newDBSettings['database'],
          'encoding' => $vm_config['mysql_databases'][0]['encoding'],
          'collation' => $vm_config['mysql_databases'][0]['collation'],
        ];
      }

      $yamlWriter->write($vm_config);
    }
  }

  /**
   * Prompts for and sets config for new database.
   *
   * @param string $site_dir
   *   The site directory name.
   *
   * @return array
   *   Return default db configuration with updated database name.
   */
  protected function setLocalDbConfig($site_dir) {
    $db = $this->getConfigValue('drupal.db');

    $db['database'] = str_replace('.', '_', $site_dir);
    $this->getConfig()->set('drupal.db', $db);

    $this->say('Local database connection details:');
    $this->io()->listing($db);

    return $db;
  }

  /**
   * Creates the default blt.yml file for a site.
   *
   * @param string $default_site_dir
   *   The default site directory.
   *
   * @return string
   *   The default site directory.
   */
  protected function createDefaultBltSiteYml($default_site_dir) {
    if (!file_exists($default_site_dir . "/blt.yml")) {
      $initial_perms = fileperms($default_site_dir);
      chmod($default_site_dir, 0777);
      // Move project.local.hostname from blt.yml to
      // sites/default/blt.yml.
      $default_site_yml = [];
      $default_site_yml['project']['local']['hostname'] = $this->getConfigValue('project.local.hostname');
      $default_site_yml['project']['local']['protocol'] = $this->getConfigValue('project.local.protocol');
      $default_site_yml['project']['machine_name'] = $this->getConfigValue('project.machine_name');
      $default_site_yml['drush']['aliases']['local'] = $this->getConfigValue('drush.aliases.local');
      $default_site_yml['drush']['aliases']['remote'] = $this->getConfigValue('drush.aliases.remote');
      YamlMunge::writeFile($default_site_dir . "/blt.yml",
        $default_site_yml);
      $project_yml = YamlMunge::parseFile($this->getConfigValue('blt.config-files.project'));
      unset($project_yml['project']['local']['hostname']);
      unset($project_yml['project']['local']['protocol']);
      unset($project_yml['project']['machine_name']);
      unset($project_yml['drush']['aliases']['local']);
      unset($project_yml['drush']['aliases']['remote']);
      YamlMunge::writeFile($this->getConfigValue('blt.config-files.project'),
        $project_yml);
      chmod($default_site_dir, $initial_perms);
    }
    return $default_site_dir;
  }

  /**
   * Creates a blt.yml file for a site.
   *
   * @param string $new_site_dir
   *   The new site directory.
   * @param string $machine_name
   *   The site machine name.
   * @param array $url
   *   The array of URL parts.
   * @param string $local_alias
   *   The local alias.
   * @param string $remote_alias
   *   The remote alias.
   * @param string $database_name
   *   The database name.
   */
  protected function createNewBltSiteYml(
    $new_site_dir,
    $machine_name,
    array $url,
    $local_alias,
    $remote_alias,
    $database_name
  ) {
    $site_yml_filename = $new_site_dir . '/blt.yml';
    $site_yml['project']['machine_name'] = $machine_name;
    $site_yml['project']['human_name'] = $machine_name;
    $site_yml['project']['local']['protocol'] = $url['scheme'];
    $site_yml['project']['local']['hostname'] = $url['host'];
    $site_yml['drush']['aliases']['local'] = $local_alias;
    $site_yml['drush']['aliases']['remote'] = $remote_alias;
    $site_yml['drupal']['db']['database'] = $database_name;
    YamlMunge::mergeArrayIntoFile($site_yml, $site_yml_filename);
  }

  /**
   * Creates the site settings directory.
   *
   * @param string $default_site_dir
   *   The default site directory.
   * @param string $new_site_dir
   *   The new site directory.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function createNewSiteDir($default_site_dir, $new_site_dir) {
    $result = $this->taskCopyDir([
      $default_site_dir => $new_site_dir,
    ])
      ->exclude(['local.settings.php', 'files'])
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();
    if (!$result->wasSuccessful()) {
      throw new BltException("Unable to create $new_site_dir.");
    }
  }

  /**
   * Creates the site config directory.
   *
   * @param string $site_dir
   *   The site directory.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function createNewSiteConfigDir($site_dir) {
    $config_dir = $this->getConfigValue('docroot') . '/' . $this->getConfigValue('cm.core.path') . '/' . $site_dir;
    $result = $this->taskFilesystemStack()
      ->mkdir($config_dir)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();
    if (!$result->wasSuccessful()) {
      throw new BltException("Unable to create $config_dir.");
    }
  }

  /**
   * Resets the config for multisites.
   */
  protected function resetMultisiteConfig() {
    /** @var \Acquia\Blt\Robo\Config\DefaultConfig $config */
    $config = $this->getConfig();
    $config->set('multisites', []);
    $config->populateHelperConfig();
  }

  /**
   * Generate the domain based on the passed-in options.
   *
   * @param array $options
   *   Command options.
   *
   * @return string
   *   The domain name.
   */
  protected function getNewSiteDomain(array $options) {
    if (empty($options['site-uri'])) {
      $uri = $this->askRequired("Production domain or subdomain name (e.g. 'example', 'example.uiowa.edu' or 'uiowaexample.com')");
    }
    else {
      $uri = $options['site-uri'];
    }

    // Add the URL scheme if not supplied.
    if (parse_url($uri, PHP_URL_SCHEME) == NULL) {
      $uri = "https://{$uri}";
    }

    if ($parsed = parse_url($uri)) {
      // Don't allow subdirectory sites, such as uiowa.edu/example.
      if (isset($parsed['path'])) {
        return new CommandError('Subdirectory sites are not supported.');
      }

      // If input contains no periods, assume that it is a subdomain of 'uiowa.edu'.
      if (strpos($parsed['host'], '.') === FALSE) {
        $uri .= '.' . UIOWA_BASE_DOMAIN;
      }
    }
    else {
      return new CommandError('Cannot parse URI for validation.');
    }

    $this->say('>>> Domain will be: ' . $uri);

    return $uri;
  }

  /**
   * Generate a new site directory name.
   *
   * @param array $options
   *   The command options.
   * @param string $domain
   *   The domain to use to generate the site directory name.
   *
   * @return string
   *   The site directory name.
   */
  protected function getNewSiteDir(array $options, $domain) {

    if (!empty($options['site-dir'])) {
      $dir = $options['site-dir'];
    }
    else {
      $parsed = parse_url($domain);

      // Suggest the supplied domain as the site directory.
      if (isset($parsed['host'])) {
        $dir = $this->askDefault("Site directory",
          $parsed['host']);
        $this->say('>>> dir: ' . $dir);
      }
      else {
        $dir = $this->askRequired("Site directory (e.g. 'example')");
      }
    }

    return $dir;
  }

  /**
   * Given a URI, create and return a unique ID.
   *
   * Used for internal subdomain and Drush alias group name, i.e. file name.
   *
   * @param array $options
   *   The command options.
   * @param string $uri
   *   The multisite URI.
   *
   * @return string
   *   The ID.
   */
  protected function generateMachineName(array $options, $uri) {
    $check = '';
    if (empty($options['machine-name'])) {
      $parsed = parse_url($uri);
      $check = $parsed['host'];
    }
    else {
      $check = $options['machine-name'];
    }

    // Check the machine-name that was passed in or the domain.
    if (substr($check, -9) === 'uiowa.edu') {
      // Don't use the suffix if the host equals uiowa.edu.
      $machineName = substr($check, 0, -10);

      // Reverse the subdomains.
      $parts = array_reverse(explode('.', $machineName));

      // Unset the www subdomain - considered the same site.
      $key = array_search('www', $parts);
      if ($key !== FALSE) {
        unset($parts[$key]);
      }
      $machineName = implode('', $parts);
    }
    else {
      // This site has a non-uiowa.edu TLD.
      $parts = explode('.', $check);

      // Unset the www subdomain - considered the same site.
      $key = array_search('www', $parts);
      if ($key !== FALSE) {
        unset($parts[$key]);
      }

      // Pop off the suffix to be used later as a prefix.
      $extension = array_pop($parts);

      // Reverse the subdomains.
      $parts = array_reverse($parts);
      $machineName = $extension . '-' . implode('', $parts);
    }

    return $machineName;
  }

  /**
   * Get an alias for a new site.
   *
   * @param string $machine_name
   *   The site machine name.
   * @param array $options
   *   The options for the command.
   * @param string $dest
   *   The type of alias (e.g. 'local' or 'remote').
   *
   * @return string
   *   The generated alias.
   */
  protected function getNewSiteAlias($machine_name, array $options, $dest) {
    $option = $dest . '-alias';
    if (!empty($options[$option])) {
      return $options[$option];
    }
    else {
      // Local should be self.
      if ($dest === 'local') {
        $default = 'self';
      }
      else {
        $default = $machine_name . '.' . $dest;
      }
      return $this->askDefault("Default $dest drush alias", $default);
    }
  }

  /**
   * Create a drush alias for a new site.
   *
   * @param string $machine_name
   *   The site machine name.
   * @param string $site_dir
   *   The site directory.
   */
  protected function createSiteDrushAlias($machine_name, $site_dir) {
    if ($this->getInspector()->isDrupalVmConfigPresent()) {
      $defaultDrupalVmDrushAliasesFile = $this->getConfigValue('blt.root') . '/scripts/drupal-vm/drupal-vm.site.yml';
      $aliases = Expander::parse(file_get_contents($defaultDrupalVmDrushAliasesFile), $this->getConfig()->export());
    }

    $aliases['local']['uri'] = $site_dir;
    $aliases['local']['root'] = '${env.cwd}/docroot';
    // unset($aliases['local']['ssh']);.
    $filename = $this->getConfigValue('drush.alias-dir') . "/$machine_name.site.yml";
    YamlMunge::mergeArrayIntoFile($aliases, $filename);

    if (file_exists($filename)) {
      $this->say('Drush file successfully created: ' . $filename);
    }
    else {
      $this->logger->warning('Unable to create drush file: ' . $filename);
    }
  }

  /**
   * Write sites.php data.
   *
   * @param string $site_dir
   *   The site directory.
   * @param array $domains
   *   The generated list of domains.
   */
  protected function writeToSitesPhpFile($site_dir, array $domains) {
    $sites = NULL;

    require_once $this->getConfigValue('docroot') . '/sites/sites.php';
    $data = '';
    $added = [];

    foreach ($domains as $domain) {

      // Check if domain is already in docroot/sites/sites.php.
      if (isset($sites) && isset($sites[$domain])) {
        $this->say("The domain <comment>$domain</comment> already exists in the sites.php file.");
      }
      else {
        $data .= <<<EOD
\$sites['{$domain}'] = '{$site_dir}';

EOD;
        $added[] = $domain;
      }
    }

    if ($data !== '') {
      $data = "// Directory aliases for {$site_dir}.\n$data\n";
      file_put_contents($this->getConfigValue('docroot') . '/sites/sites.php', $data, FILE_APPEND);
      $this->say('Added the following domains to <comment>sites.php</comment>:');
      $this->io()->listing($added);
    }

  }

  /**
   * Re-generate the drush alias so it is more useful.
   *
   * @param string $machine_name
   *   The machine name of the site.
   * @param string $site_dir
   *   The site directory.
   * @param array $domains
   *   The generated array of domains for the site.
   */
  protected function regenerateDrushAliases($machine_name, $site_dir, array $domains) {
    $filename = "{$this->getConfigValue('drush.alias-dir')}/{$machine_name}.site.yml";
    if (file_exists($filename)) {
      $default = Yaml::parse(file_get_contents($filename));
    }
    else {
      $this->logger->warning('Drush alias file does not already exist: ' . $filename);
      $default = [];
    }

    $default['local']['uri'] = $site_dir;
    $default['prod']['uri'] = $site_dir;
    $default['test']['uri'] = $domains['test'];
    $default['dev']['uri'] = $domains['dev'];

    file_put_contents($filename, Yaml::dump($default, 10, 2));
    $this->say("Updated <comment>{$machine_name}.site.yml</comment> Drush alias file with <info>local, dev, test and prod</info> aliases.");
  }

  /**
   * Remove some files that we don't need.
   *
   * @param string $site_dir
   *   The site directory.
   */
  protected function removeExtraFiles($site_dir) {
    $files = [
      "{$this->getConfigValue('docroot')}/sites/{$site_dir}/default.services.yml",
      "{$this->getConfigValue('docroot')}/sites/{$site_dir}/services.yml",
    ];

    foreach ($files as $file) {
      if (file_exists($file)) {
        unlink($file);
        $this->logger->debug("Deleted {$file}.");
      }
    }
  }

  /**
   * Install the new multisite.
   *
   * @param string $site_dir
   *   The site directory.
   * @param string $install_profile
   *   The install profile to install.
   * @param array $options
   *   The command options.
   *
   * @return bool
   *   Flag indicating whether the site was installed.
   *
   * @throws \Robo\Exception\TaskException
   */
  protected function installSite($site_dir, $install_profile, array $options) {
    if ($install_site = $this->confirm("Would you like to run site:install for $site_dir?")) {

      $options_map = [
        'sites-subdir' => 'site-dir',
        'account-name' => 'account-name',
        'account-mail' => 'account-mail',
      ];

      foreach ($options_map as $opt => $map) {
        if (isset($options[$map])) {
          $options_map[$opt] = $options[$map];
        }
        else {
          unset($options_map[$opt]);
        }
      }

      if (!$install_profile) {
        $install_profile = $this->askDefault('Install profile to install', DEFAULT_INSTALL_PROFILE);
      }

      // @todo Validate install profile exists.
      $this->taskDrush()
        ->drush('site:install')
        ->interactive(TRUE)
        ->arg($install_profile)
        ->options($options_map)
        ->run();

      // $this->taskDrush()
      // ->drush('user:role:add')
      // ->args([
      // 'administrator',
      // $uid,
      // ])
      // ->run();
      $this->taskDrush()
        ->drush('config:set')
        ->args([
          'system.site',
          'name',
          $site_dir,
        ])
        ->run();
    }

    return $install_site;
  }

}
