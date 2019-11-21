# build-model
对所有继承自`Illuminate\Database\Eloquent\Model`的类，读取其表结构，并生成相关代码

1. 生成表名常量

        class User extends Illuminate\Database\Eloquent\Model
        {
            const TABLE = 'user';
        }

2. 生成字段名常量（可设置前缀）

        class User extends Illuminate\Database\Eloquent\Model
        {
            const S_USER_ID = 'user.id';
            const F_USER_ID = 'id';
        }
        
3. 生成枚举常量（可设置前缀）并生成相关方法和语言包

        class User extends Illuminate\Database\Eloquent\Model
        {
            const STATUS_NORMAL = 'normal';
            const STATUS_FREEZE = 'freeze';
            
            public function scopeWhereStatusNormal($query) {
                $query->where('status', static::STATUS_NORMAL);
            }
            
            public function isStatusNormal() {
                return $this->status === static::STATUS_NORMAL
            }
        }
        
        // 使用方法
        
        $user = User::whereWhereStatusNormal()->first();
        dd($user->isStatusNormal());

    同时把枚举说明生成语言包放在：`resources/lang/<locale>/table.php`。可通过在 model 中实现`get<FieldName>Enums`方法或`getEnums`来定制枚举（默认情况下只处理 enum 类型的字段）：
    
        class User extends Illuminate\Database\Eloquent\Model
        {
            /**
             * @param object $field Row in "information_schema.COLUMNS"
             */
            public function getStatusEnums($field)
            {
                return [
                    'normal' => [
                        'comment' => ['zh_CN' => '正常']
                    ],
                    'freeze' => [
                        'comment' => ['zh_CN' => '冻结']
                    ],
                ];
            }
        }

具体见命令：

    > php artisan lee2son:build-model --help
    Description:
      对 Model 进行代码生成
    
    Usage:
      lee2son:build-model [options]
    
    Options:
          --table-const-name[=TABLE-CONST-NAME]              生成表名的常量名称（为空则不生成表常量）
          --gen-field-name                                   是否生成字段名常量（tableName.fieldName）
          --field-name-prefix[=FIELD-NAME-PREFIX]            字段名常量前缀
          --gen-field-shortname                              是否生成字段短名常量
          --field-shortname-prefix[=FIELD-SHORTNAME-PREFIX]  字段短名常量前缀
          --gen-field-enum                                   是否生成字段枚举常量
          --field-enum-prefix[=FIELD-ENUM-PREFIX]            枚举常量前缀
          --const-name-style[=CONST-NAME-STYLE]              常量命名风格 camel:首字母小写驼峰 Camel:首字母大写驼峰 snake:小写下划线 SNAKE:大写下划线
          --reset                                            重置（还原）