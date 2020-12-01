namespace {{ $namespace }};

trait {{ $trait_class_shortname }} {
    /**
     * Table name
     * @var string;
     */
    public static $TABLE_NAME = '{{ $table_name }}';

    /**
     * 表栏目信息
     *
     * @var array
     */
    public static $COLUMNS = [
@foreach($columns as $column)
        '{{ $column['name'] }}' => ['title' => "{{ $column['title'] }}", 'comment' => "{{ $column['comment'] }}", 'data_type' => "{{ $column['data_type'] }}"],
@endforeach
    ];

@foreach($columns as $column)
@foreach($column['enums'] as $enum)
    /**
     * {{ $column['title'] }} - {{ $enum['title'] }}
@if(!empty($enum['comment']))
     * {{ $enum['comment'] }}
@endif
     * @var string
     */
    public static ${{ strtoupper($column['name']) }}_{{ strtoupper($enum['name']) }} = '{{ $enum['value'] }}';

@endforeach
@endforeach

@foreach($columns as $column)
    /**
     * {{ $column['title'] }}
@if (!empty($column['comment']))
     * {{ $column['comment'] }}
@endif
     * @var string
     */
    public static ${{ strtoupper($column['name']) }} = '{{ $column['name'] }}';

    /**
     * {{ $column['title'] }}
@if (!empty($column['comment']))
     * {{ $column['comment'] }}
@endif
     * @var string
     */
    public static $_{{ strtoupper($column['name']) }} = '`{{ $table_name }}`.`{{ $column['name'] }}`';

@endforeach

@foreach($columns as $column)
@foreach($column['enums'] as $enum)
    /**
     * "{{ $column['name'] }}" is "{{ $enum['value'] }}" ?
     * @return bool
     */
    public function {{ \Illuminate\Support\Str::camel('is_' . $column['name'] .'_'. $enum['value']) }}() {
        return $this->{{ $column['name'] }} === static::${{ strtoupper($column['name']) }}_{{ strtoupper($enum['name']) }};
    }

    /**
     * where "{{ $column['name'] }} = {{ $enum['value'] }}"
     */
    public function {{ \Illuminate\Support\Str::camel('scope_where_' . $column['name'] .'_'. $enum['name']) }}($query) {
        $query->where('{{ $column['name'] }}', static::${{ strtoupper($column['name']) }}_{{ strtoupper($enum['name']) }});
    }

@endforeach
@endforeach
}