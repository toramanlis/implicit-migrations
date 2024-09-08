<?php

namespace Toramanlis\ImplicitMigrations\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Toramanlis\ImplicitMigrations\Generator\MigrationGenerator;
use Toramanlis\ImplicitMigrations\Database\Migrations\ImplicitMigration;

#[AsCommand(name: 'implicit-migrations:generate')]
class GenerateMigrationCommand extends Command
{
    protected const TEMPLATE_NAME = 'migration.php.tpl';

    /** @var string */
    protected $signature = 'implicit-migrations:generate {models?* : The model to generate migration for}';

    /** @var string */
    protected $description = 'Generate migration from models';

    /** @var array<string,string> */
    protected array $modelNames;

    /** array<string> */
    protected array $migrationPaths;

    protected MigrationGenerator $generator;

    public function handle()
    {
        $migrator = resolve('migrator');
        $this->migrationPaths = $migrator->paths();
        $this->modelNames = $this->argument('models') ?:
            $this->getModelNames(Config::get('database.model_paths'));

        $migrations = $this->getImplicitMigrations();
        $generator = new MigrationGenerator(static::TEMPLATE_NAME, $migrations);

        foreach ($this->modelNames as $modelFile => $modelName) {
            $migrationContents = $generator->generate($modelName);

            if (null === $migrationContents) {
                echo "\tModel {$modelName} has no changes.\n";
                continue;
            }

            $migrationPath = $this->generateMigrationFilePath((new $modelName())->getTable(), $modelFile);

            if (file_exists($migrationPath)) {
                echo "\tMigration file {$migrationPath} already exists. Skipping\n";
                continue;
            }

            file_put_contents($migrationPath, $migrationContents);
            echo "\tCreated migration: {$migrationPath}\n";
        }
    }

    protected function getModelNames($modelPaths)
    {
        $modelNames = [];
        $modelFiles = [];

        foreach ($modelPaths as $modelPath) {
            foreach (new FilesystemIterator(base_path($modelPath), FilesystemIterator::SKIP_DOTS) as $modelFile) {
                /** @var SplFileInfo $modelFile */
                require_once($modelFile->getRealPath());
                $modelFiles[] = $modelFile->getRealPath();
            }
        }

        foreach (get_declared_classes() as $className) {
            if (!is_subclass_of($className, Model::class, true)) {
                continue;
            }

            $modelFile = (new ReflectionClass($className))->getFileName();
            if (!in_array($modelFile, $modelFiles)) {
                continue;
            }

            $modelNames[$modelFile] = $className;
        }

        return $modelNames;
    }

    protected function getImplicitMigrations()
    {
        $implicitMigrations = [];

        foreach ($this->migrationPaths as $migrationPath) {
            $iterator = new FilesystemIterator($migrationPath, FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $migrationFile) {
                /** @var SplFileInfo $migrationFile */
                $fileName = $migrationFile->getRealPath();
                $migration = include($fileName);

                if (!$migration instanceof ImplicitMigration) {
                    continue;
                }

                $implicitMigrations[$fileName] = $migration;
            }
        }

        ksort($implicitMigrations, SORT_STRING);
        return $implicitMigrations;
    }

    protected function generateMigrationFilePath(string $tableName, string $modelFile): string
    {
        static $nonce = 0;

        $fileName = date('Y_m_d_His') . '_' . $nonce++ . "_implicit_migration_{$tableName}.php";

        $targetPath = database_path('migrations');

        $modelPath = $modelFile;
        do {
            $modelPath = substr($modelPath, 0, (int) strrpos($modelPath, DIRECTORY_SEPARATOR));

            foreach ($this->migrationPaths as $migrationPath) {
                if (0 === strpos($migrationPath, $modelPath)) {
                    $targetPath = $migrationPath;
                    break 2;
                }
            }
        } while ($modelPath);

        return $targetPath . DIRECTORY_SEPARATOR . $fileName;
    }
}
