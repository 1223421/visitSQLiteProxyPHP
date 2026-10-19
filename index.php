<?php
if (!preg_match("#192.168#isu", $_SERVER["REMOTE_ADDR"])) {
    exit();
}

try {
    $db = new SQLite3(__DIR__ . "/../base_rd.db");
    $db->busyTimeout(5000);

    $db->exec("PRAGMA journal_mode = WAL");
    $db->exec("PRAGMA synchronous = NORMAL");

    $db->createFunction(
        "regexp",
        function ($pattern, $value) {
            if ($value === null) {
                return 0;
            }

            $pattern = (string) $pattern;
            $value = (string) $value;

            return @preg_match("/" . $pattern . "/u", $value) === 1 ? 1 : 0;
        },
        2,
    );
} catch (Throwable $e) {
    sendJson(500, [
        "error" => "Cannot open SQLite database: " . $e->getMessage(),
    ]);

    exit();
}

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$uri = $_SERVER["REQUEST_URI"] ?? "/";

$path = parse_url($uri, PHP_URL_PATH);

if ($path !== "/api/query") {
    sendJson(404, [
        "error" => "Not found",
    ]);

    exit();
}

if ($method === "GET") {
    $query = $_GET["query"] ?? "";

    if ($query === "") {
        sendJson(400, [
            "error" => "query parameter is required",
        ]);

        exit();
    }

    $query = trim($query);
    if (preg_match('/\s+NOT_COMMIT\s*;?\s*$/i', $query)) {
        $query = preg_replace('/\s+NOT_COMMIT\s*;?\s*$/i', "", $query);
    }
    file_put_contents(__DIR__ . "/queryes.log", $query . "\n", FILE_APPEND);
    if (!isSelect($query)) {
        sendJson(400, [
            "error" => "GET supports only SELECT queries",
        ]);

        exit();
    }

    try {
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        sendJson(200, $rows);
    } catch (Throwable $e) {
        sendJson(400, [
            "error_400" => $e->getMessage() . $query,
        ]);
    }

    exit();
}

if ($method === "POST") {
    $body = file_get_contents("php://input");

    if ($body === false || $body === "") {
        sendJson(400, [
            "error" => "Empty request body",
        ]);

        exit();
    }

    try {
        $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        sendJson(400, [
            "error" => "Invalid JSON: " . $e->getMessage(),
        ]);

        exit();
    }

    $query = $request["query"] ?? "";
    $params = $request["params"] ?? [];

    if (!is_string($query) || trim($query) === "") {
        sendJson(400, [
            "error" => "query is required",
        ]);

        exit();
    }

    if (!is_array($params)) {
        sendJson(400, [
            "error" => "params must be an array",
        ]);

        exit();
    }

    $query = trim($query);
    if (preg_match('/\s+NOT_COMMIT\s*;?\s*$/i', $query)) {
        $query = preg_replace('/\s+NOT_COMMIT\s*;?\s*$/i', "", $query);
    }
    if (isSelect($query)) {
        sendJson(400, [
            "error" => "POST is for INSERT/UPDATE/DELETE queries",
        ]);

        exit();
    }

    try {
        $stmt = $db->prepare($query);

        foreach ($params as $index => $value) {
            $parameter = $index + 1;

            if ($value === null) {
                $stmt->bindValue($parameter, null, SQLITE3_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($parameter, $value ? 1 : 0, SQLITE3_INTEGER);
            } elseif (is_int($value)) {
                $stmt->bindValue($parameter, $value, SQLITE3_INTEGER);
            } elseif (is_float($value)) {
                $stmt->bindValue($parameter, (string) $value, SQLITE3_FLOAT);
            } else {
                $stmt->bindValue($parameter, (string) $value, SQLITE3_TEXT);
            }
        }

        $stmt->execute();

        $affected = $db->changes();
        sendJson(200, [
            [
                "rowsAffected" => $affected,
            ],
        ]);
    } catch (Throwable $e) {
        sendJson(400, [
            "error" => $e->getMessage(),
        ]);
    }

    exit();
}

sendJson(405, [
    "error" => "Method not allowed",
]);

exit();

function isSelect(string $sql): bool
{
    $sql = ltrim($sql);

    return (bool) preg_match('/^select(?:\s|\(|$)/i', $sql);
}

function makeJsonSafe(mixed $value): mixed
{
    if ($value === null) {
        return null;
    }

    if (is_int($value) || is_float($value) || is_bool($value)) {
        return $value;
    }

    if (is_string($value)) {
        if (mb_check_encoding($value, "UTF-8")) {
            return $value;
        }

        return base64_encode($value);
    }

    return $value;
}

function sendJson(int $status, mixed $data): never
{
    foreach ($data as &$row) {
        foreach ($row as $column => &$value) {
            $value = makeJsonSafe($value);
        }
        unset($value);
    }
    unset($row);
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );

    $statusText = match ($status) {
        200 => "OK",
        400 => "Bad Request",
        404 => "Not Found",
        405 => "Method Not Allowed",
        500 => "Internal Server Error",
        default => "Error",
    };

    http_response_code($status);
    header("Content-Type: application/json;charset=utf-8");
    header("Server: Jetty(9.4.50.v20221201)");
    echo $json;
    exit();
}
