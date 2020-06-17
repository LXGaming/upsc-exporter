<?php
declare(strict_types=1);

class UPSCExporter {

    private static array $config = [
        "ups" => "",
        "ignoredKeys" => [
            "/driver\..*/"
        ],
        "labels" => [
            "device.type",
            "ups.productid",
            "ups.type",
            "ups.vendorid"
        ],
        "mappings" => [
            "ups.beeper.status" => [
                "disabled" => 0,
                "enabled" => 1
            ],
            "ups.status" => [
                "OL" => 0,
                "OB" => 1
            ]
        ]
    ];

    private static array $labels = [];
    private static array $metrics = [];

    public static function prepare() {
        if (strlen(self::$config["ups"]) === 0) {
            http_response_code(500);
            exit();
        }

        exec("upsc " . self::$config["ups"], $output);

        self::$metrics = self::parse($output);
        self::$labels = self::extractLabels(self::$metrics);
        self::remap(self::$metrics);
    }

    public static function toPrometheus(): array {
        $label = "";
        foreach (self::$labels as $key => $value) {
            if (strlen($label) !== 0) {
                $label .= ", ";
            }

            $label .= str_replace(".", "_", $key) . "=" . "\"" . $value . "\"";
        }

        if (strlen($label) !== 0) {
            $label = "{" . $label . "}";
        }

        $array = [];
        foreach (self::$metrics as $key => $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $metric = "upsc_" . str_replace(".", "_", $key) . $label . " " . $value;
            array_push($array, $metric);
        }

        return $array;
    }

    private static function remap(array &$data): void {
        foreach ($data as $key => $value) {
            if (!array_key_exists($key, self::$config["mappings"])) {
                continue;
            }

            $mapping = self::$config["mappings"][$key];
            if (array_key_exists($value, $mapping)) {
                $data[$key] = $mapping[$value];
            } else {
                $data[$key] = -1;
            }
        }
    }

    private static function extractLabels(array &$data): array {
        $labels = [];
        foreach ($data as $key => $value) {
            if (in_array($key, self::$config["labels"])) {
                $labels[$key] = $value;
            }
        }

        $data = array_diff_key($data, $labels);
        return $labels;
    }

    private static function parse(array $lines): array {
        $data = [];
        foreach ($lines as $line) {
            $array = self::parseLine($line);
            if ($array == null) {
                continue;
            }

            $key = $array[0];
            $value = $array[1];

            if (self::matches(self::$config["ignoredKeys"], $key)) {
                continue;
            }

            $data[$key] = $value;
        }

        return $data;
    }

    private static function parseLine(string $line) {
        $delimiter = ": ";
        if (strpos($line, $delimiter) === false) {
            return null;
        }

        $array = explode($delimiter, $line, 2);
        if (count($array) < 2) {
            return null;
        }

        return $array;
    }

    private static function matches(array $patterns, string $subject): bool {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $subject) === 1) {
                return true;
            }
        }

        return false;
    }
}

UPSCExporter::prepare();

foreach (UPSCExporter::toPrometheus() as $line) {
    echo $line . "\n";
}