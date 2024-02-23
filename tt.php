<?php

// START Задача 1

// Вариант через WP_Query
$posts_query = new WP_Query;
$posts = $posts_query->query([
    'posts_per_page' => 100,
	'post_type' => 'post',
	'post_status' => 'publish',
    'meta_query' => [
        [
            'key' => 'approved',
            'value' => 1,
        ]
    ],
]);
foreach($posts as $post) {
	echo $post->ID;
}

// Также есть get_posts() и query_posts(), оба метода работают на основе WP_Query
// и использовать их нецелесообразно. Зачем вызывать дополнительную функцию?

echo "<hr>";

// Вариант через wpdb
// Предпочтительный вариант, так как обработка через него быстрее, чем через WP_Query,
// а также можно выбирать именно те данные, которые нужны для вывода
global $wpdb;
$posts = $wpdb->get_results(
    $wpdb->prepare("
        SELECT ID
        FROM $wpdb->posts, $wpdb->postmeta
        WHERE $wpdb->posts.post_type = 'post'
        AND $wpdb->posts.post_status = 'publish'
        AND $wpdb->postmeta.meta_key = 'approved'
        AND $wpdb->postmeta.meta_value = 1
        LIMIT 100
    ")
);
foreach( $posts as $post ) {
	echo $post->ID;
}

// Также можно использовать SQL запрос на чистом PHP, но $wpdb изначально имеет защиту от инъекций,
// при использовании $wpdb->prepare(), а также они кэшируются ядром WP, что сказывается на производительности

// END Задача 1

echo "<hr>";
echo "<hr>";
echo "<hr>";

// START Задача 2


$template_json = file_get_contents(get_stylesheet_directory().'/registration/counter.json');
$template_data = json_decode($template_json);

$template_type = 'first';

if ($template_data) { // Проверяем на наличие данных

    $template_data = (array) $template_data;

    // Находим шаблон с минимальным кол-вом показов, позываем его и и обновляем данные
    $template_min = array_keys($template_data, min($template_data))[0];
    $template_data[$template_min] = $template_data[$template_min] + 1;
    $template_type = $template_min;

    $template_json = json_encode($template_data);
    file_put_contents(get_stylesheet_directory().'/registration/counter.json', $template_json);

} else { // Если файла нет, то показываем рандомный шаблон и создаем json

    $template_first = 0;
    $template_second = 0;
    $template_third = 0;
    $template_rand = mt_rand(1, 3);
    if ($template_rand == 1) {
        $template_first = 1;
        $template_type = 'first';
    } elseif ($template_rand == 2) {
        $template_second = 1;
        $template_type = 'second';
    } else {
        $template_third = 1;
        $template_type = 'third';
    }
    $template_data = array(
        'first' => $template_first,
        'second' => $template_second,
        'third' => $template_third
    );

    $template_json = json_encode($template_data);
    file_put_contents(get_stylesheet_directory().'/registration/counter.json', $template_json);

}

$template_data = [
    'key1' => 'value',
    'key2' => [ "value1", "value2" ]
];
get_template_part( 'registration/template', $template_type, $template_data );

// END Задача 2

echo "<hr>";
echo "<hr>";
echo "<hr>";

// START Задача 3

class Logger {
    public static $PATH;
    protected static $loggers = array();

    protected $name;
    protected $file;
    protected $fp;

    public function __construct($name, $file=null) {
        $this->name = $name;
        $this->file = $file;
        $this->open();
    }

    public function open() {
        if(self::$PATH == null) {
            return;
        }
        $this->fp=fopen($this->file==null ? self::$PATH.'/'.$this->name.'.log' : self::$PATH.'/'.$this->file,'a+');
    }

    public static function getLogger($name='root',$file=null) {
        if(!isset(self::$loggers[$name])) {
            self::$loggers[$name] = new Logger($name, $file);
        }
        return self::$loggers[$name];
    }

    public function log($message) {
        if(!is_string($message)) {
            $this->logPrint($message);
            return;
        }
        $log = '';
        $log .= '['.date('D M d H:i:s Y',time()).'] ';
        if(func_num_args()>1) {
            $params = func_get_args();
            $message = call_user_func_array('sprintf',$params);
        }
        $log .= $message;
        $log .= "\n";
        $this->_write($log);
    }

    public function logPrint($obj) {
        ob_start();
        print_r($obj);
        $ob = ob_get_clean();
        $this->log($ob);
    }

    protected function _write($string) {
        fwrite($this->fp, $string);
        echo $string;
    }

    public function __destruct() {
        fclose($this->fp);
    }
}

// Задаем директорию
Logger::$PATH = get_stylesheet_directory().'/logs/';

// Задаем название файла и данные
$name = 'log';
$data = [
    'key1' => 'value',
    'key2' => [ "value1", "value2" ]
];
Logger::getLogger($name)->log($data);

// END Задача 3

?>