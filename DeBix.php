<?php

declare(strict_types=1);

/**
 * DeBix (Deobfuscator Bitrix)
 * Продвинутый инструмент деобфускации PHP для Bitrix и другого обфусцированного PHP кал..кхм. кода
 * GitHub: https://github.com/FaLLenSKiLL1/DeBix/
 *
 * @Author FaLLenSKiLL
 * @version 1.0
 * @requires PHP 8.3+
 */

enum FilePattern: string
{
    case BASE64_DECODE = '= array(base64_decode(';
    case GLOBALS_ARRAY = 'GLOBALS.*array';
    case BASE64_GENERIC = 'base64_decode';
}

enum VariableType
{
    case STRING;
    case VARIABLE_REF;
    case SPECIAL;
}

readonly class DeobfuscationConfig
{
    public function __construct(
        public int $repeater_count = 2,
        public string $mappings_file = 'deobfuscation_map.php',
        public string $custom_mappings_file = 'custom_mappings.php',
        public ?string $project_path = null,
        public bool $auto_confirm = false,
        public bool $enable_backup = true,
        public int $max_file_size_mb = 10,
        public bool $learn_new_variables = true,
        public bool $remove_obfuscated_blocks = true,
        public bool $format_output = true,
        public bool $enable_pattern_learning = true,
        public bool $load_custom_mappings = true
    ) {
    }
}

final class ObfuscatedVariable
{
    public function __construct(
        public string $obfuscated_name,
        public string $clean_value,
        public VariableType $type,
        public string $source_file = '',
        public string $learned_date = ''
    ) {
    }
}

final class PatternLearner
{
    public function __construct(
        private string $codebase_path = '.',
        private array $file_patterns = ['*.php', '*.js', '*.html', '*.txt']
    ) {
    }

    public function discover_patterns(): array
    {
        $this->log("Поиск общих паттернов в коде...");

        $patterns = [
            'variable_names' => $this->find_common_variables(),
            'function_names' => $this->find_common_functions(),
            'string_values' => $this->find_common_strings(),
            'method_calls' => $this->find_common_methods()
        ];

        $this->log("Найдено " . array_sum(array_map('count', $patterns)) . " потенциальных паттернов");
        return $patterns;
    }

    private function find_common_variables(): array
    {
        $variables = [];
        $files = $this->find_files();

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Находим общие паттерны переменных PHP
            preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=/', $content, $matches);
            foreach ($matches[1] as $var) {
                if (strlen($var) > 2 && strlen($var) < 20) {
                    $variables[$var] = ($variables[$var] ?? 0) + 1;
                }
            }

            // Поиск общих паттернов переменных JavaScript
            preg_match_all('/(?:var|let|const)\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*=/', $content, $matches);
            foreach ($matches[1] as $var) {
                if (strlen($var) > 2 && strlen($var) < 20) {
                    $variables[$var] = ($variables[$var] ?? 0) + 1;
                }
            }
        }

        // Возвращаем наиболее частые переменные
        arsort($variables);
        return array_slice(array_keys($variables), 0, 50);
    }

    private function find_common_functions(): array
    {
        $functions = [];
        $files = $this->find_files();

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false)
                continue;

            // Находим определения функций
            preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $content, $matches);
            foreach ($matches[1] as $func) {
                if (strlen($func) > 3 && !str_starts_with($func, '_')) {
                    $functions[$func] = ($functions[$func] ?? 0) + 1;
                }
            }

            // Находим вызовы методов
            preg_match_all('/->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $content, $matches);
            foreach ($matches[1] as $method) {
                if (strlen($method) > 3) {
                    $functions[$method] = ($functions[$method] ?? 0) + 1;
                }
            }
        }

        arsort($functions);
        return array_slice(array_keys($functions), 0, 30);
    }

    private function find_common_strings(): array
    {
        $strings = [];
        $files = $this->find_files();

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false)
                continue;

            // Находим строки в кавычках
            preg_match_all('/[\'"]([^\'"]{3,50})[\'"]/', $content, $matches);
            foreach ($matches[1] as $string) {
                if ($this->is_meaningful_string($string)) {
                    $strings[$string] = ($strings[$string] ?? 0) + 1;
                }
            }
        }

        arsort($strings);
        return array_slice(array_keys($strings), 0, 100);
    }

    private function find_common_methods(): array
    {
        $methods = [];
        $files = $this->find_files();

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false)
                continue;

            // Находим общие паттерны методов PHP
            preg_match_all('/(?:->|::)([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $content, $matches);
            foreach ($matches[1] as $method) {
                if (strlen($method) > 3) {
                    $methods[$method] = ($methods[$method] ?? 0) + 1;
                }
            }
        }

        arsort($methods);
        return array_slice(array_keys($methods), 0, 25);
    }

    private function find_files(): array
    {
        $files = [];
        $patterns = array_map(fn($p) => $this->codebase_path . '/**/' . $p, $this->file_patterns);

        foreach ($patterns as $pattern) {
            $found = glob($pattern, defined('GLOB_BRACE') ? GLOB_BRACE : 0);
            if ($found !== false) {
                $files = array_merge($files, $found);
            }
        }

        // Отфильтруем обфусцированные и деобфусцированные файлы
        return array_filter(
            $files,
            fn($file) => !str_contains($file, '_deobfuscated.') &&
            !str_contains($file, 'deobfuscation_map.php') &&
            !str_contains($file, 'custom_mappings.php') &&
            !str_contains($file, 'DeBix.php')
        );
    }

    private function is_meaningful_string(string $str): bool
    {
        // Отфильтруем бессмысленные строки
        if (is_numeric($str)) {
            return false;
        }
        if (preg_match('/^[0-9a-f]{8,}$/i', $str)) {
            return false;
        } // Hex strings
        if (preg_match('/^[a-z0-9]{32,}$/i', $str)) {
            return false;
        } // MD5-like
        if (strlen($str) < 3) {
            return false;
        }
        if (preg_match('/^[^a-zA-Z]+$/', $str)) {
            return false;
        } // No letters

        return true;
    }

    private function log(string $message): void
    {
        echo $message . \PHP_EOL;
    }
}

final class DeBix
{
    private string $file;
    private string $project_path;

    public string $console_output = '';

    private array $globalVariables = [];
    private array $globalFunctions = [];
    private array $functionBlocksToDelete = [];
    private array $ifBlocksToDelete = [];

    /** @var array<string, ObfuscatedVariable> */
    private array $knownVariables = [];

    private int $processed_files = 0;
    private int $skipped_files = 0;
    private int $obfuscated_files_found = 0;

    public function __construct(
        private readonly DeobfuscationConfig $config = new DeobfuscationConfig()
    ) {
        $this->init();
    }

    private function console_log(string $str): void
    {
        $this->console_output .= $str . \PHP_EOL;
        echo $str . \PHP_EOL;
    }

    private function read_input(string $prompt): string
    {
        echo $prompt;
        return trim(fgets(\STDIN) ?: '');
    }

    public function init(): void
    {
        $php_version = \PHP_VERSION;
        $php_major_version = \PHP_MAJOR_VERSION;

        $this->console_log("=== DeBix (Bitrix Deobfuscator) Запущен ===");
        $this->console_log("Версия PHP: {$php_version} (Мажорная: {$php_major_version})");
        $this->console_log("Рабочая директория: " . getcwd());

        // Получаем путь к проекту
        global $argv;
        $this->project_path = $argv[1] ?? '';

        if (empty($this->project_path)) {
            $this->project_path = $this->read_input("Введите путь к директории проекта: ");
        }

        if (empty($this->project_path) || !is_dir($this->project_path)) {
            $this->console_log("Ошибка: Некорректный путь к директории.");
            return;
        }

        $this->project_path = realpath($this->project_path);
        $this->console_log("Путь проекта: " . $this->project_path);
        $this->console_log("Конфигурация: " . $this->get_config_summary());

        // Загружаем известные обфусцированные переменные
        $this->load_known_variables();

        $obfuscated_files = $this->find_obfuscated_files_with_grep();

        if (empty($obfuscated_files)) {
            $this->console_log("Обфусцированные файлы не найдены.");
            return;
        }

        $this->obfuscated_files_found = count($obfuscated_files);
        $this->console_log("Найдено файлов для деобфускации: {$this->obfuscated_files_found}");

        // Создаем бекап всех файлов
        if ($this->config->enable_backup) {
            $this->create_zip_backup($obfuscated_files);
        }

        // Подтверждение
        if (!$this->config->auto_confirm) {
            $confirm = $this->read_input("\nПриступить к деобфускации? (y/n): ");
            if (!str_starts_with(strtolower($confirm), 'y')) {
                $this->console_log("Деобфускация отменена пользователем.");
                return;
            }
        }

        // Определяем общие константы Bitrix, чтобы eval не падал
        if (!defined('SITE_CHARSET'))
            define('SITE_CHARSET', 'UTF-8');
        if (!defined('BX_UTF'))
            define('BX_UTF', true);
        if (!defined('LANG_CHARSET'))
            define('LANG_CHARSET', 'UTF-8');

        $this->console_log("\nНачинаю процесс деобфускации...\n");

        foreach ($obfuscated_files as $file) {
            $this->process_file($file);
        }

        // Сохраняем обновленные переменные
        $this->save_known_variables();

        $this->generate_summary_report();
    }

    private function get_config_summary(): string
    {
        $features = [
            $this->config->learn_new_variables ? 'Обучение' : 'Без обучения',
            $this->config->remove_obfuscated_blocks ? 'Удаление блоков' : 'Без удаления блоков',
            $this->config->format_output ? 'Форматирование' : 'Без форматирования',
            $this->config->enable_backup ? 'Бекап (ZIP)' : 'Без бекапа',
            $this->config->enable_pattern_learning ? 'Паттерны' : 'Без паттернов',
            $this->config->load_custom_mappings ? 'Кастомные маппинги' : 'Без кастомных маппингов'
        ];

        return implode(' | ', $features);
    }

    private function create_zip_backup(array $files): void
    {
        if (!class_exists('ZipArchive')) {
            $this->console_log("Предупреждение: Расширение PHP 'zip' не найдено. Бекап в ZIP пропущен.");
            $this->console_log("Для включения бекапа установите расширение (например: apt install php-zip).");
            return;
        }

        $zip_file = 'backup_' . date('Y-m-d_H-i-s') . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zip_file, ZipArchive::CREATE) !== true) {
            $this->console_log("Ошибка: Не удалось создать ZIP-архив для бекапа.");
            return;
        }

        foreach ($files as $file) {
            if (file_exists($file)) {
                $zip->addFile($file, $file);
            }
        }

        $zip->close();
        $this->console_log("Бекап создан: {$zip_file}");
    }

    private function load_known_variables(): void
    {
        $all_mappings = [];

        // Загружаем автоматические маппинги
        if (file_exists($this->config->mappings_file)) {
            try {
                $loaded_data = include $this->config->mappings_file;
                $mappings = $loaded_data['mappings'] ?? $loaded_data;
                $all_mappings = array_merge($all_mappings, $mappings);
                $this->console_log("Загружено " . count($mappings) . " автоматических маппингов");
            } catch (Throwable $e) {
                $this->console_log("Ошибка загрузки файла маппингов: " . $e->getMessage());
            }
        }

        // Загружаем пользовательские маппинги
        if ($this->config->load_custom_mappings && file_exists($this->config->custom_mappings_file)) {
            try {
                $custom_data = include $this->config->custom_mappings_file;
                $custom_mappings = $custom_data['mappings'] ?? $custom_data;
                $all_mappings = array_merge($all_mappings, $custom_mappings);
                $this->console_log("Загружено " . count($custom_mappings) . " пользовательских маппингов");
            } catch (Throwable $e) {
                $this->console_log("Ошибка загрузки пользовательских маппингов: " . $e->getMessage());
            }
        }

        // Convert to ObfuscatedVariable objects
        $this->knownVariables = array_map(
            fn($name, $value) => new ObfuscatedVariable(
                (string) $name,
                $value,
                str_starts_with($value, '$') ? VariableType::VARIABLE_REF : VariableType::STRING
            ),
            array_keys($all_mappings),
            $all_mappings
        );

        $this->console_log("Всего загружено маппингов: " . count($this->knownVariables));

        // Обучение на основе паттернов, если включено
        if ($this->config->enable_pattern_learning) {
            $this->learn_from_codebase_patterns();
        }
    }

    private function learn_from_codebase_patterns(): void
    {
        $this->console_log("Анализ паттернов кода для улучшения деобфускации...");

        $learner = new PatternLearner($this->project_path);
        $patterns = $learner->discover_patterns();

        $new_learnings = 0;

        // Обучение на основе частых имен переменных
        foreach ($patterns['variable_names'] as $variable) {
            $potential_obfuscated = '_' . crc32($variable);
            if (!isset($this->knownVariables[$potential_obfuscated])) {
                $this->knownVariables[$potential_obfuscated] = new ObfuscatedVariable(
                    (string) $potential_obfuscated,
                    '$' . $variable,
                    VariableType::VARIABLE_REF
                );
                $new_learnings++;
            }
        }

        // Обучение на основе частых строк
        foreach ($patterns['string_values'] as $string) {
            $potential_obfuscated = '____' . crc32($string);
            if (!isset($this->knownVariables[$potential_obfuscated])) {
                $this->knownVariables[$potential_obfuscated] = new ObfuscatedVariable(
                    (string) $potential_obfuscated,
                    $string,
                    VariableType::STRING
                );
                $new_learnings++;
            }
        }

        if ($new_learnings > 0) {
            $this->console_log("Изучено {$new_learnings} новых паттернов из анализа кода");
        } else {
            $this->console_log("Новых паттернов в коде не обнаружено");
        }
    }

    private function save_known_variables(): void
    {
        if (!$this->config->learn_new_variables) {
            $this->console_log("Обучение переменным отключено - пропуск сохранения");
            return;
        }

        $mappings_array = array_combine(
            array_map(fn($v) => $v->obfuscated_name, $this->knownVariables),
            array_map(fn($v) => $v->clean_value, $this->knownVariables)
        );

        $content = "<?php\n\n";
        $content .= "// DeBix Deobfuscation Mappings\n";
        $content .= "// НЕ РЕДАКТИРОВАТЬ ВРУЧНУЮ - Создано DeBix Deobfuscator\n";
        $content .= "// Этот файл хранит маппинги обфусцированных переменных\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "return [\n";
        $content .= "    'metadata' => [\n";
        $content .= "        'version' => '2.0', \n";
        $content .= "        'created' => '" . date('Y-m-d H:i:s') . "',\n";
        $content .= "        'records' => " . count($this->knownVariables) . ",\n";
        $content .= "        'checksum' => '" . md5(serialize($mappings_array)) . "',\n";
        $content .= "        'generator' => 'DeBix v1.0',\n";
        $content .= "        'source_file' => '" . basename(__FILE__) . "'\n";
        $content .= "    ],\n";
        $content .= "    'mappings' => [\n";

        foreach ($mappings_array as $var => $value) {
            $content .= match (true) {
                str_starts_with($value, '$') => "        '{$var}' => '{$value}',\n",
                default => "        '{$var}' => '" . addslashes($value) . "',\n"
            };
        }

        $content .= "    ]\n";
        $content .= "];\n";

        // Бекап файла маппингов
        if (file_exists($this->config->mappings_file)) {
            $backup_file = $this->config->mappings_file . '.backup';
            copy($this->config->mappings_file, $backup_file);
            $this->console_log("Создан бекап маппингов: " . basename($backup_file));
        }

        if (file_put_contents($this->config->mappings_file, $content, LOCK_EX) !== false) {
            $this->console_log("Сохранено " . count($this->knownVariables) . " маппингов в " . $this->config->mappings_file);
        } else {
            $this->console_log("Ошибка: Не удалось сохранить маппинги в " . $this->config->mappings_file);
        }
    }

    private function find_obfuscated_files_with_grep(): array
    {
        $obfuscated_files = [];
        $current_script = realpath(__FILE__);
        $mappings_file = realpath($this->config->mappings_file);

        // Поиск паттернов
        $patterns = [
            FilePattern::BASE64_DECODE->value,
            FilePattern::GLOBALS_ARRAY->value,
            FilePattern::BASE64_GENERIC->value
        ];

        foreach ($patterns as $pattern) {
            $grep_command = "grep -rl --include=\"*.php\" \"{$pattern}\" \"{$this->project_path}\" 2>/dev/null";
            $this->console_log("Запуск: {$grep_command}");

            exec($grep_command, $output, $return_var);

            if ($return_var === 0 && !empty($output)) {
                foreach ($output as $file_path) {
                    $full_path = realpath($file_path);
                    if (
                        $full_path &&
                        $full_path !== $current_script &&
                        $full_path !== $mappings_file &&
                        !in_array($file_path, $obfuscated_files, true)
                    ) {

                        $file_size = filesize($file_path);
                        if ($file_size <= ($this->config->max_file_size_mb * 1024 * 1024)) {
                            $obfuscated_files[] = $file_path;
                            $this->console_log("  Найден: " . basename($file_path) . " (" . $this->format_size($file_size) . ")");
                        } else {
                            $this->console_log("  Пропущен: " . basename($file_path) . " (превышен лимит размера)");
                        }
                    } elseif ($full_path === $current_script) {
                        $this->console_log("  Пропущен: " . basename($file_path) . " (скрипт деобфускатора)");
                    } elseif ($full_path === $mappings_file) {
                        $this->console_log("  Пропущен: " . basename($file_path) . " (файл маппингов)");
                    }
                }
                break;
            }
        }

        if (empty($obfuscated_files)) {
            $this->console_log("  Обфусцированные файлы не найдены.");
        }

        return $obfuscated_files;
    }

    private function process_file(string $file_path): void
    {
        $filename = basename($file_path);

        $this->console_log("\n" . str_repeat("=", 60));
        $this->console_log("ОБРАБОТКА: {$filename}");
        $this->console_log("Расположение: " . dirname($file_path));
        $this->console_log(str_repeat("=", 60));

        if (!file_exists($file_path)) {
            $this->console_log("Ошибка: Файл не найден");
            $this->skipped_files++;
            return;
        }

        $file_size = filesize($file_path);
        if ($file_size > ($this->config->max_file_size_mb * 1024 * 1024)) {
            $this->console_log("Ошибка: Файл слишком большой (" . $this->format_size($file_size) . ")");
            $this->skipped_files++;
            return;
        }

        $file_content = file_get_contents($file_path);
        if ($file_content === false) {
            $this->console_log("Ошибка: Не удалось прочитать файл");
            $this->skipped_files++;
            return;
        }

        $this->file = $file_content;
        $this->console_log("Размер файла: " . $this->format_size(strlen($this->file)));

        // Сброс состояния
        $this->globalVariables = [];
        $this->globalFunctions = [];
        $this->functionBlocksToDelete = [];
        $this->ifBlocksToDelete = [];

        // Шаги обработки
        $processing_steps = [
            'Извлечение обфусцированных функций' => $this->define_obfuscated_functions(...),
            'Поиск обфусцированных имен' => $this->find_obfuscated_names(...),
            'Обработка переменных и массивов' => $this->edit_variables_array(...),
            'Обработка функций' => $this->edit_variables_function(...),
            'Очистка числовых переменных' => $this->cleanup_numeric_variables(...),
            'Подготовка кода' => $this->prepare(...),
        ];

        if ($this->config->remove_obfuscated_blocks) {
            $processing_steps['Удаление обфусцированных блоков'] = $this->remove_obfuscated_blocks(...);
        }

        if ($this->config->format_output) {
            $processing_steps['Форматирование кода'] = $this->format_code(...);
        }

        $processing_steps['Очистка известных переменных'] = $this->apply_known_variables_cleanup(...);

        foreach ($processing_steps as $description => $step) {
            $this->console_log("Шаг: {$description}...");
            $step();
        }

        // Сохранение "на месте"
        $output_path = $this->get_output_path($file_path);

        if (file_put_contents($output_path, $this->file, LOCK_EX) !== false) {
            $new_size = $this->format_size(strlen($this->file));
            $this->console_log("Успех: Файл деобфусцирован");
            $this->console_log("Изменение размера: " . $this->format_size(strlen($file_content)) . " → {$new_size}");
            $this->processed_files++;
        } else {
            $this->console_log("Ошибка: Не удалось сохранить деобфусцированный файл");
            $this->skipped_files++;
        }
    }

    private function cleanup_numeric_variables(): void
    {
        // Поиск переменных вида $_1840255548
        preg_match_all('/\$(_\d{5,})/', $this->file, $matches);
        $numeric_vars = array_unique($matches[1]);

        if (empty($numeric_vars)) {
            return;
        }

        $this->console_log("  Найдено " . count($numeric_vars) . " числовых переменных для анализа");

        foreach ($numeric_vars as $var) {
            // Если мы уже чему-то научились для этой переменной, пропустим (она обработается в apply_known_variables_cleanup)
            if (isset($this->knownVariables[$var])) {
                continue;
            }

            // Попробуем Находим присваивание этой переменной чего-то "простого"
            // Например: $_1019487189= str_replace('\\', '/', __FILE__);
            if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*([^;]{3,100});/', $this->file, $assignment)) {
                $value = trim($assignment[1]);
                // Если значение выглядит как вызов функции или строка, запомним его
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\(.*\)$/', $value) || preg_match('/^[\'"].*[\'"]$/', $value)) {
                    // Пока просто логируем, но можно было бы добавить в knownVariables
                    // $this->knownVariables[$var] = new ObfuscatedVariable($var, $value, VariableType::STRING);
                }
            }
        }
    }

    private function apply_known_variables_cleanup(): void
    {
        if (empty($this->knownVariables)) {
            $this->console_log("  Нет известных переменных для применения");
            return;
        }

        $replacements = 0;
        // Сортировка по длине имени (сначала длинные), чтобы избежать частичных замен
        usort($this->knownVariables, fn($a, $b) => strlen((string) $b->obfuscated_name) - strlen((string) $a->obfuscated_name));

        foreach ($this->knownVariables as $variable) {
            $name = (string) $variable->obfuscated_name;
            if ($variable->clean_value === '$' . $name) {
                continue;
            }

            $usage_pattern = '/\\$' . preg_quote($name, '/') . '\b/';
            $replacement = match ($variable->type) {
                VariableType::VARIABLE_REF => $variable->clean_value,
                VariableType::STRING, VariableType::SPECIAL => "'" . addslashes((string) $variable->clean_value) . "'"
            };

            $before_count = substr_count($this->file, '$' . $name);
            $this->file = preg_replace($usage_pattern, $replacement, $this->file);
            $after_count = substr_count($this->file, '$' . $name);
            $current_replacements = $before_count - $after_count;

            if ($current_replacements > 0) {
                $replacements += $current_replacements;
                $this->console_log("  Заменено \${$name} на {$replacement} ({$current_replacements} раз)");
            }
        }

        $this->console_log("  Применено {$replacements} очисток переменных из базы данных");
    }

    private function generate_summary_report(): void
    {
        $this->console_log("\n" . str_repeat("=", 60));
        $this->console_log("ОТЧЕТ О РАБОТЕ DeBix (Bitrix Deobfuscator)");
        $this->console_log(str_repeat("=", 60));
        $this->console_log("Найдено обфусцированных файлов: {$this->obfuscated_files_found}");
        $this->console_log("Процесс завершен для: {$this->processed_files}");
        $this->console_log("Пропущено/ошибки: {$this->skipped_files}");
        $this->console_log("Маппингов сохранено: " . count($this->knownVariables));
        $this->console_log("Файл маппингов: " . $this->config->mappings_file);
        $this->console_log("Кастомные маппинги: " . $this->config->custom_mappings_file);
        $this->console_log("Результат: Файлы перезаписаны в оригинальных директориях");
        $this->console_log("Версия PHP: " . \PHP_VERSION);

        match (true) {
            $this->processed_files > 0 => $this->console_log("\nДеобфускация успешно завершена!"),
            default => $this->console_log("\nФайлы не были обработаны.")
        };

        $this->console_log(str_repeat("=", 60));
    }

    private function format_size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 2) . ' МБ',
            $bytes >= 1024 => round($bytes / 1024, 2) . ' КБ',
            default => $bytes . ' байт'
        };
    }

    private function get_output_path(string $original_path): string
    {
        return $original_path;
    }

    private function define_obfuscated_functions(): void
    {
        $globalArrayPattern = '/(\$GLOBALS\[\'(_{3,}[0-9]+)\'\]\s*=\s*array\([^;]+\);)/s';
        preg_match_all($globalArrayPattern, $this->file, $matches, \PREG_SET_ORDER);

        if (!empty($matches)) {
            $this->console_log("  Найдено " . count($matches) . " глобальных массивов");
            foreach ($matches as $match) {
                $this->console_log("  Выполнение глобального массива: " . substr($match[1], 0, 50) . "...");
                try {
                    @eval ($match[1]);
                } catch (\Throwable $e) {
                    $this->console_log("  Ошибка выполнения глобального массива: " . $e->getMessage());
                }

                if (preg_match('/\$GLOBALS\[\'([^\']+)\'\]/', $match[1], $var_match)) {
                    $this->learn_variable_values($var_match[1]);
                }
            }
        }

        $functionPattern = '/(function\s+_{3,}[0-9]+\([^)]*\)\s*\{((?:[^{}]*|(?R))*)\})/s';
        preg_match_all($functionPattern, $this->file, $matches, \PREG_SET_ORDER);

        if (!empty($matches)) {
            $this->console_log("  Найдено " . count($matches) . " обфусцированных функций");
            foreach ($matches as $match) {
                try {
                    @eval ($match[0]);
                } catch (\Throwable $e) {
                    $this->console_log("  Ошибка определения функции: " . $e->getMessage());
                }
                $this->functionBlocksToDelete[] = $match[0];
            }
        }

        $ifPattern = '/(if\s*\(!function_exists\(__NAMESPACE__\s*\.\s*\'\\\\(_{3,}[0-9]+)\'\)\)\s*\{.*\};)/sU';
        preg_match_all($ifPattern, $this->file, $matches, \PREG_SET_ORDER);
        if (!empty($matches)) {
            $this->console_log("  Найдено " . count($matches) . " оберток функций if-block");
            foreach ($matches as $match) {
                $this->ifBlocksToDelete[] = $match[0];
            }
        }
    }

    // Зачем ты читаешь комменты, лол
    private function learn_variable_values(string $var_name): void
    {
        if (isset($GLOBALS[$var_name]) && is_array($GLOBALS[$var_name])) {
            foreach ($GLOBALS[$var_name] as $index => $value) {
                if (is_string($value) && strlen($value) > 1) {
                    $obfuscated_var = $var_name . '_' . $index;
                    if (!isset($this->knownVariables[$obfuscated_var])) {
                        $this->knownVariables[$obfuscated_var] = new ObfuscatedVariable(
                            (string) $obfuscated_var,
                            $value,
                            VariableType::STRING
                        );
                        $this->console_log("  Изучено: \${$obfuscated_var} = '{$value}'");
                    }
                }
            }
        }
    }

    private function find_obfuscated_names(): void
    {
        preg_match_all('/\$GLOBALS\[\'(_{3,}[0-9]+)\'\]/', $this->file, $matches);
        $this->globalVariables = array_unique($matches[1]);
        $this->console_log("  Найдено " . count($this->globalVariables) . " глобальных переменных: " . implode(
            ', ',
            $this->globalVariables
        ));

        preg_match_all('/(_{3,}[0-9]+)\(/', $this->file, $matches);
        $this->globalFunctions = array_unique($matches[1]);
        $this->console_log("  Найдено " . count($this->globalFunctions) . " обфусцированных функций: " . implode(
            ', ',
            $this->globalFunctions
        ));
    }

    private function remove_obfuscated_blocks(): void
    {
        $original_length = strlen($this->file);
        foreach ($this->globalVariables as $value) {
            $pattern = '/\$GLOBALS\[\'' . $value . '\'\]\s*=\s*array\(.*?\);/s';
            $this->file = preg_replace($pattern, '', $this->file);
        }
        foreach ($this->globalFunctions as $functionName) {
            $pattern = '/if\s*\(\s*!\s*function_exists\s*\(\s*__NAMESPACE__\s*\.\s*\'\\\\[^\']*\'\s*\)\s*\).*?return[^;]*;.*?\}\s*;\s*/s';
            $this->file = preg_replace($pattern, "\n", $this->file);
        }
        $new_length = strlen($this->file);
        $reduction = $original_length - $new_length;
        $this->console_log("  Удалено " . $this->format_size($reduction) . " обфусцированного кода");
    }

    private function edit_variables_array(): void
    {
        foreach ($this->globalVariables as $value) {
            $this->console_log("  Обработка глобальной переменной: {$value}");
            $this->file = preg_replace_callback(
                '/\$GLOBALS\[\'' . $value . '\'\]\[(\d+)\]\s*\(([^)]*)\)/',
                function ($matches) use ($value) {
                    $index = $matches[1];
                    $params = $matches[2];
                    if (isset($GLOBALS[$value]) && isset($GLOBALS[$value][$index])) {
                        $funcName = $GLOBALS[$value][$index];
                        return $funcName . '(' . $params . ')';
                    }
                    return $matches[0];
                },
                $this->file
            );

            $this->file = preg_replace_callback(
                '/\$GLOBALS\[\'' . $value . '\'\]\[(\d+)\](?!\s*\()/',
                function ($matches) use ($value) {
                    $index = $matches[1];
                    if (isset($GLOBALS[$value]) && isset($GLOBALS[$value][$index])) {
                        $result = $GLOBALS[$value][$index];
                        return match (true) {
                            is_string($result) => "'" . addslashes($result) . "'",
                            default => $result
                        };
                    }
                    return $matches[0];
                },
                $this->file
            );
        }
    }

    private function edit_variables_function(): void
    {
        foreach ($this->globalFunctions as $value) {
            $this->file = preg_replace_callback('/' . $value . '\((\d+)\)/', function ($matches) use ($value) {
                if (!function_exists($value)) {
                    return $matches[0];
                }
                try {
                    return "'" . addslashes((string) $value($matches[1])) . "'";
                } catch (\Throwable $e) {
                    return $matches[0];
                }
            }, $this->file);
        }
    }

    private function prepare(): void
    {
        for ($i = 0; $i < $this->config->repeater_count; $i++) {
            $this->prepare_func();
        }
        $this->prepare_compute();
    }

    private function prepare_func(): void
    {
        $this->file = preg_replace_callback(
            '/(min|round|strtoupper|strrev|base64_decode|set_time_limit)\([^()]+\)/',
            function ($matches) {
                try {
                    $result = @eval ("return " . $matches[0] . ";");
                    return match (gettype($result)) {
                        'string' => "'" . addslashes((string) $result) . "'",
                        'double', 'integer' => $result,
                        default => $matches[0]
                    };
                } catch (\Throwable $e) {
                    return $matches[0];
                }
            },
            $this->file
        );
    }

    private function prepare_compute(): void
    {
        $this->file = preg_replace_callback('/\(([0-9-+*\/\s]{2,}?)\)/', function ($matches) {
            try {
                return @eval ("return " . $matches[1] . ";");
            } catch (\Throwable $e) {
                return $matches[0];
            }
        }, $this->file);
    }

    private function format_code(): void
    {
        $this->file = preg_replace('/\s+/', ' ', $this->file);
        $this->file = trim($this->file);
        $this->file = preg_replace('/;(?!\s*\})/', ";\n", $this->file);
        $this->file = preg_replace('/(\S)\s*{/', "$1\n{", $this->file);
        $this->file = preg_replace('/{\s*(\S)/', "{\n$1", $this->file);
        $this->file = preg_replace('/(\S)\s*}/', "$1\n}", $this->file);
        $this->file = preg_replace('/}\s*(\S)/', "}\n$1", $this->file);
        $this->file = preg_replace('/(?<=\d)\s*\.\s*(?=\'[^\']+\')/', ' . ', $this->file);
        $this->file = preg_replace('/}\s*(else\s*(if\s*\(|{)|elseif\s*\()/', "}\n$1", $this->file);
        $this->file = preg_replace('/(if|elseif|for|while|foreach|switch|function)\s*\(/', "\n$1 (", $this->file);
        $this->file = preg_replace(
            '/(\S)\s*(class|interface|trait|private|public|protected|static|abstract|final)\s/',
            "$1\n$2 ",
            $this->file
        );
        $this->file = preg_replace('/([^\)\s])\s*return\s/', "$1\nreturn ", $this->file);
        $this->file = preg_replace('/(\S)\s*(case\s|default\s*:)/', "$1\n$2", $this->file);
        $this->file = preg_replace('/(\S)\s*(break\s*;|continue\s*;)/', "$1\n$2", $this->file);
        $this->file = preg_replace('/\n\s*\n\s*\n/', "\n\n", $this->file);
        $this->file = $this->add_indentation($this->file);
    }

    private function add_indentation(string $code): string
    {
        $lines = explode("\n", $code);
        $formatted_lines = [];
        $indent_level = 0;
        $tab = "    ";
        foreach ($lines as $line) {
            $trimmed_line = trim($line);
            if (empty($trimmed_line)) {
                $formatted_lines[] = '';
                continue;
            }
            if (str_starts_with($trimmed_line, '}') || str_starts_with($trimmed_line, ')') || str_starts_with($trimmed_line, ']')) {
                $indent_level = max(0, $indent_level - 1);
            }
            $current_indent = $indent_level;
            if (str_starts_with($trimmed_line, 'case ') || str_starts_with($trimmed_line, 'default')) {
                $current_indent = max(0, $indent_level - 1);
            }
            $formatted_lines[] = str_repeat($tab, $current_indent) . $trimmed_line;
            if (str_ends_with($trimmed_line, '{') || str_ends_with($trimmed_line, '(') || str_ends_with($trimmed_line, '[')) {
                $indent_level++;
            }
            if (str_ends_with($trimmed_line, ':') && (str_starts_with($trimmed_line, 'case ') || str_starts_with($trimmed_line, 'default'))) {
                $indent_level++;
            }
            if ((str_starts_with($trimmed_line, 'break') || str_starts_with($trimmed_line, 'continue')) && $indent_level > 0) {
                $indent_level--;
            }
        }
        return implode("\n", $formatted_lines);
    }
}

if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    if (php_sapi_name() === 'cli') {
        $debix = new DeBix();
    } else {
        echo '<pre>';
        $debix = new DeBix();
        echo '</pre>';
    }
}
