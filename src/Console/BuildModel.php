<?php

namespace ModelHelper\Console;

use Composer\Autoload\ClassMapGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ReflectionClass;

class BuildModel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lee2son:build-model';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Model trait';

    /**
     * @var array 语言包
     */
    protected $languages = [];

    /**
     * Execute the console command.
     * @throws \ReflectionException
     */
    public function handle()
    {
        $databases = [];
        $tables = [];

        foreach(ClassMapGenerator::createMap(app_path()) as $className => $classFile)
        {
            $classReflection  = new ReflectionClass($className);
            if(!$classReflection->isSubclassOf('Illuminate\Database\Eloquent\Model')) continue;
            if($classReflection->isAbstract()) continue;
            if($classReflection->isTrait()) continue;

            /**
             * @var \Illuminate\Database\Eloquent\Model $model;expression is not allowed as field default value
             */
            $model = $classReflection->newInstance();

            if($model->getConnection()->getDriverName() !== 'mysql') continue;

            $connectionName = $model->getConnectionName();
            $classShortName = $classReflection->getShortName();
            $databaseName = $model->getConnection()->getDatabaseName();
            $traitClassShortName = $classShortName . 'Trait';
            $tableName = $model->getTable();

            if(!isset($tables[$tableName])) {
                $sql = "SELECT * FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = ? AND `TABLE_NAME` = ? limit 1";

                $table = $model->getConnection()->select($sql, [$databaseName, $tableName]);
                if(count($table) === 0) {
                    $this->getOutput()->getErrorStyle()->error("Table \"{$databaseName}.{$tableName}\" not exists. in \"{$className}\"");
                    continue;
                }

                $table = $tables[$tableName] = $table[0];
            } else {
                $table = $tables[$tableName];
            }

            $sql = "SELECT * FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = ? AND `TABLE_NAME` = ?";
            $tableColumns = $model->getConnection()->select($sql, [$databaseName, $tableName]);

            $columns = [];
            foreach($tableColumns as $column)
            {
                $enums = $this->getColumnEnums($model, $column);
                $info = array_merge(['data_type' => $column->DATA_TYPE], $this->getColumnInfo($model, $column));

                $columns[$column->COLUMN_NAME] = [
                    'name' => $column->COLUMN_NAME,
                    'title' => $info['title'] ?? null,
                    'comment' => $info['comment'] ?? null,
                    'data_type' => $column->DATA_TYPE,
                    'raw_info' => $column,
                    'enums' => $enums,
                    'raw_info' => $column,
                ];
            }

            $traitFilePath = dirname($classFile) . '/' . $traitClassShortName . '.php';
            $traitCode = "<?php\n\n" . view('model-helper::model_trait', [
                'class_shortname' => $classShortName,
                'class_name' => $className,
                'trait_class_shortname' => $traitClassShortName,
                'namespace' => $classReflection->getNamespaceName(),
                'database_name' => $databaseName,
                'table_name' => $tableName,
                'columns' => $columns,
            ])->render();

            file_put_contents($traitFilePath, $traitCode);

            if(!isset($databases[$connectionName])) {
                $databases[$connectionName] = [
                    'database_name' => $databaseName,
                    'tables' => []
                ];
            }

            if(!isset($databases[$connectionName]['tables'][$tableName])) {
                $databases[$connectionName]['tables'][$tableName] = [
                    'raw_info' => $table,
                    'columns' => [],
                ];
            }

            $databases[$connectionName]['tables'][$tableName]['columns'] = $columns;
        }

        echo json_encode($databases);
    }

    /**
     * 获取表的栏目信息
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param object $column
     * @return array
     */
    protected function getColumnInfo($model, $column)
    {
        $method = Str::camel(implode('_', ['get', $column->COLUMN_NAME, 'ColumnInfo']));
        if(method_exists($model, $method)) {
            return call_user_func([$model, $method], $column);
        } elseif(method_exists($model, 'getColumnInfo')) {
            return call_user_func([$model, 'getColumnInfo'], $column);
        } else {
            return ['title' => strtoupper($column->COLUMN_NAME), 'comment' => $column->COLUMN_COMMENT];
        }
    }

    /**
     * 解析枚举字段
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param object $column
     * @return array|null
     */
    protected function getColumnEnums($model, $column)
    {
        $method = Str::camel(implode('_', ['get', $column->COLUMN_NAME, 'ColumnEnums']));
        if(method_exists($model, $method)) {
            $columnEnums = call_user_func([$model, $method], $column);
        } elseif($column->DATA_TYPE === 'enum' && method_exists($model, 'getColumnEnums')) {
            $columnEnums = call_user_func([$model, 'getColumnEnums'], $column);
        } else {
            return [];
        }

        $data = [];

        foreach($columnEnums as $option) {
            $data[$option['value']] = [
                'name' => $option['as'] ?? $option['value'],
                'value' => $option['value'],
                'title' => $option['title'],
                'comment' => $option['comment'],
            ];
        }

        return $data;
    }
}
