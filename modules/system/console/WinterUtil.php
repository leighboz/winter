<?php namespace System\Console;

use Lang;
use File;
use Config;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use System\Classes\UpdateManager;
use System\Classes\CombineAssets;
use System\Models\Parameter;
use System\Models\File as FileModel;

/**
 * Console command for other utility commands.
 *
 * This provides functionality that doesn't quite deserve its own dedicated
 * console class. It is used mostly developer tools and maintenance tasks.
 *
 * Currently supported commands:
 *
 * - purge thumbs: Deletes all thumbnail files in the uploads directory.
 * - git pull: Perform "git pull" on all plugins and themes.
 * - compile assets: Compile registered Language, LESS and JS files.
 * - compile js: Compile registered JS files only.
 * - compile less: Compile registered LESS files only.
 * - compile scss: Compile registered SCSS files only.
 * - compile lang: Compile registered Language files only.
 * - set project --projectId=<id>: Set the projectId for this winter instance.
 *
 * @package winter\wn-system-module
 * @author Alexey Bobkov, Samuel Georges
 */
class WinterUtil extends Command
{

    use \Illuminate\Console\ConfirmableTrait;

    /**
     * The console command name.
     */
    protected $name = 'winter:util';

    /**
     * The console command description.
     */
    protected $description = 'Utility commands for Winter';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();

        // Register aliases for backwards compatibility with October
        $this->setAliases(['october:util']);
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $command = implode(' ', (array) $this->argument('name'));
        $method = 'util'.studly_case($command);

        $methods = preg_grep('/^util/', get_class_methods(get_called_class()));
        $list = array_map(function ($item) {
            return "winter:".snake_case($item, " ");
        }, $methods);

        if (!$this->argument('name')) {
            $message = 'There are no commands defined in the "util" namespace.';
            if (1 == count($list)) {
                $message .= "\n\nDid you mean this?\n    ";
            } else {
                $message .= "\n\nDid you mean one of these?\n    ";
            }

            $message .= implode("\n    ", $list);
            throw new \InvalidArgumentException($message);
        }

        if (!method_exists($this, $method)) {
            $this->error(sprintf('Utility command "%s" does not exist!', $command));
            return;
        }

        $this->$method();
    }

    /**
     * Get the console command arguments.
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['name', InputArgument::IS_ARRAY, 'The utility command to perform, For more info "http://wintercms.com/docs/console/commands#winter-util-command".'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions()
    {
        return [
            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production.'],
            ['debug', null, InputOption::VALUE_NONE, 'Run the operation in debug / development mode.'],
            ['projectId', null, InputOption::VALUE_REQUIRED, 'Specify a projectId for set project'],
            ['missing-files', null, InputOption::VALUE_NONE, 'Purge system_files records for missing storage files'],
        ];
    }

    //
    // Utilties
    //

    protected function utilSetBuild()
    {
        $this->comment('NOTE: This command is now deprecated. Please use "php artisan winter:version" instead.');
        $this->comment('');

        return $this->call('winter:version');
    }

    protected function utilCompileJs()
    {
        $this->utilCompileAssets('js');
    }

    protected function utilCompileLess()
    {
        $this->utilCompileAssets('less');
    }

    protected function utilCompileScss()
    {
        $this->utilCompileAssets('scss');
    }

    protected function utilCompileAssets($type = null)
    {
        $this->comment('Compiling registered asset bundles...');

        Config::set('cms.enableAssetMinify', !$this->option('debug'));
        $combiner = CombineAssets::instance();
        $bundles = $combiner->getBundles($type);

        if (!$bundles) {
            $this->comment('Nothing to compile!');
            return;
        }

        if ($type) {
            $bundles = [$bundles];
        }

        foreach ($bundles as $bundleType) {
            foreach ($bundleType as $destination => $assets) {
                $destination = File::symbolizePath($destination);
                $publicDest = File::localToPublic(realpath(dirname($destination))) . '/' . basename($destination);

                $combiner->combineToFile($assets, $destination);
                $shortAssets = implode(', ', array_map('basename', $assets));
                $this->comment($shortAssets);
                $this->comment(sprintf(' -> %s', $publicDest));
            }
        }

        if ($type === null) {
            $this->utilCompileLang();
        }
    }

    protected function utilCompileLang()
    {
        if (!$locales = Lang::get('system::lang.locale')) {
            return;
        }

        $this->comment('Compiling client-side language files...');

        $locales = array_keys($locales);
        $stub = base_path() . '/modules/system/assets/js/lang/lang.stub';

        foreach ($locales as $locale) {
            /*
             * Generate messages
             */
            $fallbackPath = base_path() . '/modules/system/lang/en/client.php';
            $srcPath = base_path() . '/modules/system/lang/'.$locale.'/client.php';

            $messages = require $fallbackPath;

            if (File::isFile($srcPath) && $fallbackPath != $srcPath) {
                $messages = array_replace_recursive($messages, require $srcPath);
            }

            /*
             * Load possible replacements from /lang
             */
            $overrides = [];
            $parentOverrides = [];

            $overridePath = base_path() . '/lang/'.$locale.'/system/client.php';
            if (File::isFile($overridePath)) {
                $overrides = require $overridePath;
            }

            if (str_contains($locale, '-')) {
                list($parentLocale, $country) = explode('-', $locale);

                $parentOverridePath = base_path() . '/lang/'.$parentLocale.'/system/client.php';
                if (File::isFile($parentOverridePath)) {
                    $parentOverrides = require $parentOverridePath;
                }
            }

            $messages = array_replace_recursive($messages, $parentOverrides, $overrides);

            /*
             * Compile from stub and save file
             */
            $destPath = base_path() . '/modules/system/assets/js/lang/lang.'.$locale.'.js';

            $contents = str_replace(
                ['{{locale}}', '{{messages}}'],
                [$locale, json_encode($messages)],
                File::get($stub)
            );

            /*
             * Include the moment localization data
             */
            $momentPath = base_path() . '/modules/system/assets/ui/vendor/moment/locale/'.$locale.'.js';
            if (File::exists($momentPath)) {
                $contents .= PHP_EOL.PHP_EOL.File::get($momentPath).PHP_EOL;
            }

            File::put($destPath, $contents);

            /*
             * Output notes
             */
            $publicDest = File::localToPublic(realpath(dirname($destPath))) . '/' . basename($destPath);

            $this->comment($locale.'/'.basename($srcPath));
            $this->comment(sprintf(' -> %s', $publicDest));
        }
    }

    protected function utilPurgeThumbs()
    {
        if (!$this->confirmToProceed('This will PERMANENTLY DELETE all thumbs in the uploads directory.')) {
            return;
        }

        $totalCount = 0;
        $uploadsPath = Config::get('filesystems.disks.local.root', storage_path('app'));
        $uploadsPath .= '/uploads';

        /*
         * Recursive function to scan the directory for files beginning
         * with "thumb_" and repeat itself on directories.
         */
        $purgeFunc = function ($targetDir) use (&$purgeFunc, &$totalCount) {
            if ($files = File::glob($targetDir.'/thumb_*')) {
                foreach ($files as $file) {
                    $this->info('Purged: '. basename($file));
                    $totalCount++;
                    @unlink($file);
                }
            }

            if ($dirs = File::directories($targetDir)) {
                foreach ($dirs as $dir) {
                    $purgeFunc($dir);
                }
            }
        };

        $purgeFunc($uploadsPath);

        if ($totalCount > 0) {
            $this->comment(sprintf('Successfully deleted %s thumbs', $totalCount));
        }
        else {
            $this->comment('No thumbs found to delete');
        }
    }

    protected function utilPurgeResizedCache()
    {
        if (!$this->confirmToProceed('This will PERMANENTLY DELETE all images in the resized directory.')) {
            return;
        }

        $totalCount = 0;
        $uploadsPath = Config::get('cms.storage.resized.disks.root', storage_path('app')) . '/resized';

        // Recursive function to scan the directory for files and ensure they exist in system_files.
        $purgeImagesFunc = function ($targetDir) use (&$purgeImagesFunc, &$totalCount, $uploadsPath) {
            if ($files = File::glob($targetDir.'/*')) {
                if ($dirs = File::directories($targetDir)) {
                    foreach ($dirs as $dir) {
                        $purgeImagesFunc($dir);

                        if (File::isDirectoryEmpty($dir) && is_writeable($dir)) {
                            rmdir($dir);
                            $this->info('Removed folder: '. str_replace($uploadsPath, '', $dir));
                        }
                    }
                }

                foreach ($files as $file) {
                    if (!is_file($file)) {
                        continue;
                    }

                    // Skip .gitignore files
                    if ($file === '.gitignore') {
                        continue;
                    }

                    // Skip files unable to be purged
                    if (!is_writeable($file)) {
                        $this->warn('Unable to purge file: ' . str_replace($uploadsPath, '', $file));
                        continue;
                    }

                    unlink($file);
                    $this->info('Purged: '. str_replace($uploadsPath, '', $file));
                    $totalCount++;
                }
            }
        };

        $purgeImagesFunc($uploadsPath);

        if ($totalCount > 0) {
            $this->comment(sprintf('Successfully deleted %s resized images', $totalCount));
        }
        else {
            $this->comment('No resized images found to delete');
        }
    }

    protected function utilPurgeUploads()
    {
        if (!$this->confirmToProceed('This will PERMANENTLY DELETE files in the uploads directory that do not exist in the "system_files" table.')) {
            return;
        }

        $uploadsDisk = Config::get('cms.storage.uploads.disk', 'local');
        if ($uploadsDisk !== 'local') {
            $this->error("Purging uploads is only supported on the 'local' disk, current uploads disk is $uploadsDisk");
            return;
        }

        $totalCount = 0;
        $validFiles = FileModel::pluck('disk_name')->all();
        $uploadsPath = Config::get('filesystems.disks.local.root', storage_path('app')) . '/' . Config::get('cms.storage.uploads.folder', 'uploads');

        // Recursive function to scan the directory for files and ensure they exist in system_files.
        $purgeFunc = function ($targetDir) use (&$purgeFunc, &$totalCount, $uploadsPath, $validFiles) {
            if ($files = File::glob($targetDir.'/*')) {
                if ($dirs = File::directories($targetDir)) {
                    foreach ($dirs as $dir) {
                        $purgeFunc($dir);

                        if (File::isDirectoryEmpty($dir) && is_writeable($dir)) {
                            rmdir($dir);
                            $this->info('Removed folder: '. str_replace($uploadsPath, '', $dir));
                        }
                    }
                }

                foreach ($files as $file) {
                    if (!is_file($file)) {
                        continue;
                    }

                    // Skip .gitignore files
                    if ($file === '.gitignore') {
                        continue;
                    }

                    // Skip files unable to be purged
                    if (!is_writeable($file)) {
                        $this->warn('Unable to purge file: ' . str_replace($uploadsPath, '', $file));
                        continue;
                    }

                    // Skip valid files
                    if (in_array(basename($file), $validFiles)) {
                        $this->warn('Skipped file in use: '. str_replace($uploadsPath, '', $file));
                        continue;
                    }

                    unlink($file);
                    $this->info('Purged: '. str_replace($uploadsPath, '', $file));
                    $totalCount++;
                }
            }
        };

        $purgeFunc($uploadsPath);

        if ($totalCount > 0) {
            $this->comment(sprintf('Successfully deleted %d invalid file(s), leaving %d valid files', $totalCount, count($validFiles)));
        } else {
            $this->comment('No files found to purge.');
        }
    }

    protected function utilPurgeOrphans()
    {
        if (!$this->confirmToProceed('This will PERMANENTLY DELETE files in "system_files" that do not belong to any other model.')) {
            return;
        }

        $isDebug = $this->option('debug');
        $orphanedFiles = 0;
        $isLocalStorage = Config::get('cms.storage.uploads.disk', 'local') === 'local';

        $files = FileModel::whereDoesntHaveMorph('attachment', '*')
                    ->orWhereNull('attachment_id')
                    ->orWhereNull('attachment_type')
                    ->get();

        foreach ($files as $file) {
            if (!$isDebug) {
                $file->delete();
            }
            $orphanedFiles += 1;
        }

        if ($this->option('missing-files') && $isLocalStorage) {
            foreach (FileModel::all() as $file) {
                if (!File::exists($file->getLocalPath())) {
                    if (!$isDebug) {
                        $file->delete();
                    }
                    $orphanedFiles += 1;
                }
            }
        }

        if ($orphanedFiles > 0) {
            $this->comment(sprintf('Successfully deleted %d orphaned record(s).', $orphanedFiles));
        } else {
            $this->comment('No records to purge.');
        }
    }

    /**
     * This command requires the git binary to be installed.
     */
    protected function utilGitPull()
    {
        foreach (File::directories(plugins_path()) as $authorDir) {
            foreach (File::directories($authorDir) as $pluginDir) {
                if (!File::exists($pluginDir.'/.git')) {
                    continue;
                }

                $exec = 'cd ' . $pluginDir . ' && ';
                $exec .= 'git pull 2>&1';
                echo 'Updating plugin: '. basename(dirname($pluginDir)) .'.'. basename($pluginDir) . PHP_EOL;
                echo shell_exec($exec);
            }
        }

        foreach (File::directories(themes_path()) as $themeDir) {
            if (!File::exists($themeDir.'/.git')) {
                continue;
            }

            $exec = 'cd ' . $themeDir . ' && ';
            $exec .= 'git pull 2>&1';
            echo 'Updating theme: '. basename($themeDir) . PHP_EOL;
            echo shell_exec($exec);
        }
    }

    protected function utilSetProject()
    {
        $projectId = $this->option('projectId');

        if (empty($projectId)) {
            $this->error("No projectId defined, use --projectId=<id> to set a projectId");
            return;
        }

        $manager = UpdateManager::instance();
        $result = $manager->requestProjectDetails($projectId);

        Parameter::set([
            'system::project.id'    => $projectId,
            'system::project.name'  => $result['name'],
            'system::project.owner' => $result['owner'],
        ]);
    }
}
