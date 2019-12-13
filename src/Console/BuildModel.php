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
    protected $signature = 'lee2son:build-model {--table-const-name= : 生成表名的常量名称（为空则不生成表常量）} 
                                                {--gen-column-name : 是否生成字段名常量（tableName.columnName）}
                                                {--column-name-prefix= : 字段名常量前缀}
                                                {--gen-column-shortname : 是否生成字段短名常量}
                                                {--column-shortname-prefix= : 字段短名常量前缀}
                                                {--gen-column-enum : 是否生成字段枚举常量}
                                                {--column-enum-prefix= : 枚举常量前缀}
                                                {--gen-columns : 生成所有字段的信息}
                                                {--const-name-style= : 常量命名风格 camel:首字母小写驼峰 Camel:首字母大写驼峰 snake:小写下划线 SNAKE:大写下划线}
                                                {--reset : 重置（还原）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '对 Model 进行代码生成';

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
        $tableConstName = $this->option('table-const-name', null);
        $isGenColumnEnum = $this->option('gen-column-enum');

        $isGenColumnName = $this->option('gen-column-name', false);
        $columnNamePrefix = $this->option('column-name-prefix', '');

        $isGenColumnShortname = $this->option('gen-column-shortname', false);
        $columnShortnamePrefix = $this->option('column-shortname-prefix', '');

        $isGenColumns = $this->option('gen-columns', false);

        foreach(ClassMapGenerator::createMap(app_path()) as $className => $classFile)
        {
            $constants = $methods = $columns = [];

            // 重置

            if($this->option('reset')) {
                goto generate;
            }

            $classReflection  = new ReflectionClass($className);
            if(!$classReflection->isSubclassOf('Illuminate\Database\Eloquent\Model')) continue;
            if($classReflection->isAbstract()) continue;

            /**
             * @var \Illuminate\Database\Eloquent\Model $model;
             */
            $model = $classReflection->newInstance();

            $classShortName = $classReflection->getShortName();
            $databaseName = $model->getConnection()->getDatabaseName();
            $tableName = $model->getTable();

            if($tableConstName) {
                $constants[] = $this->genTableConstCode($tableConstName, $tableName);
            }

            $sql = "SELECT * FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = ? AND `TABLE_NAME` = ?";
            foreach($model->getConnection()->select($sql, [$databaseName, $tableName]) as $column)
            {
                $builder = get_class($model->newModelQuery());

                if($isGenColumnEnum) {
                    foreach($this->handleColumnEnum($model, $column) as $enum) {
                        $constants[] = $this->genColumnEnumConstCode($enum);
                        $methods[] = $this->genColumnEnumWhereMethodCode($enum, $builder);
                        $methods[] = $this->genColumnEnumIsMethodCode($enum);
                    }
                }

                if($isGenColumnName) {
                    $constants[] = $this->genColumnConstCode($column, $columnNamePrefix, $tableName);
                }

                if($isGenColumnShortname) {
                    $constants[] = $this->genColumnShortNameConstCode($column, $columnShortnamePrefix);
                }

                if($isGenColumns) {
                    $columns[$column->COLUMN_NAME] = array_merge([
                        'data_type' => $column->DATA_TYPE,
                    ], $this->genColumnInfo($model, $column) ?: ['title' => strtoupper($column->COLUMN_NAME), 'desc' => $column->COLUMN_COMMENT]);
                }
            }

generate:

            $code = file_get_contents($classFile);
            if(!$code) continue;
            $code = preg_replace('%#generated-columns-code-block.*?#generated-columns-code-block\n\n%s', '', $code);
            if(count($columns)) {
                $columnsCode = implode("\n", array_map(function($line) {
                    return "\t". $line;
                }, explode("\n", var_export($columns, true))));
                $columnsCode = "#generated-columns-code-block\n\n\tpublic static \$allColumn ={$columnsCode};\n\n#generated-columns-code-block";
                $code = preg_replace('%\bclass\s+'.$classShortName.'\b.*?\{\n*%s', "\\0{$columnsCode}\n\n", $code);
            }

            $code = preg_replace('%#generated-const-code-block.*?#generated-const-code-block\n\n%s', '', $code);
            if(count($constants)) {
                $constantCode = trim(implode("\n\n", $constants));
                $constantCode = "#generated-const-code-block\n\n\t{$constantCode}\n\n#generated-const-code-block";
                $code = preg_replace('%\bclass\s+'.$classShortName.'\b.*?\{\n*%s', "\\0{$constantCode}\n\n", $code);
            }

            $code = preg_replace('%\n\n#generated-method-code-block.*?#generated-method-code-block%s', '', $code);
            if(count($methods)) {
                $methodCode = trim(implode("\n\n", $methods));
                $methodCode = "#generated-method-code-block\n\n\t{$methodCode}\n\n#generated-method-code-block";
                $code = preg_replace('/\n*\}\s*$/', "\n\n{$methodCode}\\0", $code);
            }

            file_put_contents($classFile, $code);
        }

        foreach($this->languages as $locale => $data) {
            file_put_contents(resource_path("lang/{$locale}/table.php"), "<?php\nreturn " . var_export($data, true) . ';');
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param $column
     * @return array
     */
    protected function handleColumnEnum($model, $column)
    {
        $data = [];

        $method = Str::camel(implode('_', ['get', $column->COLUMN_NAME, 'ColumnEnums']));
        if(method_exists($model, $method)) {
            $enums = call_user_func([$model, $method], $column);
        } elseif($column->DATA_TYPE === 'enum' && method_exists($model, 'getColumnEnums')) {
            $enums = call_user_func([$model, 'getColumnEnums'], $column);
        } else {
            return [];
        }

        foreach($enums as $value => $attr) {
            $enum = [
                'name' => $this->option('column-enum-prefix') . $this->name($column->COLUMN_NAME, $attr['as'] ?? $value),
                'value' => $value,
                'column' => $column,
                'column' => $column->COLUMN_NAME,
                'comment' => '',
                'description' => $column->COLUMN_COMMENT,
                'deprecated' => $attr['deprecated'] ?? false,
            ];

            if(is_array($attr['comment'])) {
                foreach($attr['comment'] as $locale => $comment) {
                    $enum['comment'] = $comment;

                    $this->appendEnumTable(
                        $locale,
                        $model->getConnectionName(),
                        $model->getTable(),
                        $column->COLUMN_NAME,
                        $value,
                        $comment
                    );
                }

                $enum['comment'] = $attr['comment'][config('app.locale')] ?? $enum['comment'];
            } else {
                $this->appendEnumTable(
                    config('app.locale'),
                    $model->getConnectionName(),
                    $model->getTable(),
                    $column->COLUMN_NAME,
                    $value,
                    $attr['comment']
                );

                $enum['comment'] = $attr['comment'];
            }

            $data[] = $enum;
        }

        return $data;
    }

    /**
     * 往枚举表添加枚举说明
     * @param $locale
     * @param $connectionName
     * @param $tableName
     * @param $columnName
     * @param $value
     * @param $comment
     */
    protected function appendEnumTable($locale, $connectionName, $tableName, $columnName, $value, $comment)
    {
        if(!isset($this->languages[$locale])) {
            $this->languages[$locale] = [];
        }

        if(!isset($this->languages[$locale][$connectionName])) {
            $this->languages[$locale][$connectionName] = [];
        }

        if(!isset($this->languages[$locale][$connectionName][$tableName])) {
            $this->languages[$locale][$connectionName][$tableName] = [];
        }


        if(!isset($this->languages[$locale][$connectionName][$tableName][$columnName])) {
            $this->languages[$locale][$connectionName][$tableName][$columnName] = [];
        }

        $this->languages[$locale][$connectionName][$tableName][$columnName][$value] = $comment;
    }

    /**
     * @param mixed ...$name
     * @return string
     */
    protected function name(...$name) {
        $name = implode('_', $name);
        switch($this->option('const-name-style')) {
            case 'camel': return Str::camel($name);
            case 'Camel': return ucfirst(Str::camel($name));
            case 'snake': return strtolower(Str::snake($name));
            case 'SNAKE': return strtoupper(Str::snake($name));
            default: return implode($name);
        }
    }

    /**
     * 将多行代码合并
     * @param array $code
     * @param string $indent
     * @param string $end
     * @return string
     */
    protected function genCode(array $code, $indent = "", $end = "\n")
    {
        $str = '';
        foreach($code as $line)
        {
            $str .= $indent . $line . $end;
        }

        return $str;
    }

    /**
     * 生成表的定义
     * @param $constName
     * @param $tableName
     * @return string
     */
    protected function genTableConstCode($constName, $tableName)
    {
        $lines = [];
        $lines[] = "/**";
        $lines[] = " * Table name.";
        $lines[] = " */";
        $lines[] = "const {$constName} = '{$tableName}';";
        return $this->genCode($lines, "\t");
    }

    /**
     * 生成枚举常量的定义
     * @param $enum
     * @return string
     */
    protected function genColumnEnumConstCode($enum)
    {
        $lines = [];
        $lines[] = "/**";
        $lines[] = " * {$enum['comment']}";
        $lines[] = " * {$enum['description']}";
        if($enum['deprecated']) {
            $lines[] = " * @deprecated";
        }
        $lines[] = " */";
        $lines[] = "const {$enum['name']} = '{$enum['value']}';";

        return $this->genCode($lines, "\t");
    }

    /**
     * 生成枚举字段的 where 方法
     * @param array $enum
     * @param string $builder
     * @return string
     */
    protected function genColumnEnumWhereMethodCode($enum, $builder)
    {
        $methodName = Str::camel(implode('_', ['scope', 'where', $enum['column'], $enum['value']]));

        $lines = [];
        $lines[] = "/**";
        $lines[] = " * As \"where {$enum['column']} = {$enum['value']}\"";
        if($enum['deprecated']) {
            $lines[] = " * @deprecated";
        }
        $lines[] = " * @param \\{$builder} \$query";
        $lines[] = " */";
        $lines[] = "public function {$methodName}(\\{$builder} \$query)";
        $lines[] = "{";
        $lines[] = "\t\$query->where('{$enum['column']}', static::{$enum['name']});";
        $lines[] = "}";

        return $this->genCode($lines, "\t");
    }

    /**
     * 生成“判断字段枚举值是否等于某值”的方法
     * @param $enum
     * @return string
     */
    protected function genColumnEnumIsMethodCode($enum)
    {
        $methodName = Str::camel(implode('_', ['is', $enum['column'], $enum['value']]));

        $lines = [];
        $lines[] = "/**";
        $lines[] = " * \"{$enum['column']}\" is \"{$enum['value']}\"?";
        if($enum['deprecated']) {
            $lines[] = " * @deprecated";
        }
        $lines[] = " * @return bool";
        $lines[] = " */";
        $lines[] = "public function {$methodName}()";
        $lines[] = "{";
        $lines[] = "\treturn \$this->{$enum['column']} === static::{$enum['name']};";
        $lines[] = "}";

        return $this->genCode($lines, "\t");
    }

    /**
     * 生成字段的常量定义
     * @param $column
     * @param $prefix
     * @param $tableName
     * @return string
     */
    protected function genColumnConstCode($column, $prefix, $tableName)
    {
        $constName = $this->name($column->COLUMN_NAME);

        $lines = [];
        $lines[] = "/**";
        $lines[] = " * Column name by {$column->COLUMN_NAME}";
        $lines[] = " * {$column->COLUMN_COMMENT}";
        $lines[] = " */";
        $lines[] = "const {$prefix}{$constName} = '{$tableName}.{$column->COLUMN_NAME}';";

        return $this->genCode($lines, "\t");
    }

    /**
     * 生成字段的短名字常量定义
     * @param $column
     * @param $prefix
     * @return string
     */
    protected function genColumnShortNameConstCode($column, $prefix)
    {
        $constName = $this->name($column->COLUMN_NAME);

        $lines = [];
        $lines[] = "/**";
        $lines[] = " * Column shortname by {$column->COLUMN_NAME}";
        $lines[] = " * {$column->COLUMN_COMMENT}";
        $lines[] = " */";
        $lines[] = "const {$prefix}{$constName} = '{$column->COLUMN_NAME}';";

        return $this->genCode($lines, "\t");
    }

    /**
     * 获取表的栏目信息
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param $column
     * @return array
     */
    protected function genColumnInfo($model, $column)
    {
        $method = Str::camel(implode('_', ['get', $column->COLUMN_NAME, 'ColumnInfo']));
        if(method_exists($model, $method)) {
            return call_user_func([$model, $method], $column);
        } elseif(method_exists($model, 'getColumnInfo')) {
            return call_user_func([$model, 'getColumnInfo'], $column);
        } else {
            return;
        }
    }
}