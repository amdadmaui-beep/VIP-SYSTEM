<?php
declare(strict_types=1);

error_reporting(E_ALL);
set_time_limit(0);

$host = '0.0.0.0';
$port = 8090;
$queueFile = __DIR__ . '/queue/events.log';
$clients = [];
$lastReadPos = 0;

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "WebSocket server error: {$errstr} ({$errno})\n");
    exit(1);
}
stream_set_blocking($server, false);
echo "WebSocket server listening on ws://{$host}:{$port}\n";

if (file_exists($queueFile)) {
    $lastReadPos = filesize($queueFile) ?: 0;
}

function wsEncode(string $payload): string
{
    $len = strlen($payload);
    if ($len <= 125) {
        return chr(129) . chr($len) . $payload;
    }
    if ($len <= 65535) {
        return chr(129) . chr(126) . pack('n', $len) . $payload;
    }
    return chr(129) . chr(127) . pack('J', $len) . $payload;
}

function performHandshake($client, string $request): bool
{
    if (!preg_match("/Sec-WebSocket-Key:\s*(.*)\r\n/i", $request, $matches)) {
        return false;
    }
    $key = trim($matches[1]);
    $accept = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
    fwrite($client, $upgrade);
    return true;
}

function readNewEvents(string $queueFile, int &$lastReadPos): array
{
    if (!file_exists($queueFile)) {
        return [];
    }
    $size = filesize($queueFile);
    if ($size === false || $size <= $lastReadPos) {
        return [];
    }

    $fp = fopen($queueFile, 'rb');
    if (!$fp) {
        return [];
    }
    fseek($fp, $lastReadPos);
    $chunk = stream_get_contents($fp);
    $lastReadPos = ftell($fp);
    fclose($fp);

    $events = [];
    foreach (preg_split('/\r?\n/', (string)$chunk) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }
    return $events;
}

while (true) {
    $read = [$server];
    foreach ($clients as $clientInfo) {
        $read[] = $clientInfo['socket'];
    }

    $write = null;
    $except = null;
    @stream_select($read, $write, $except, 0, 200000);

    foreach ($read as $sock) {
        if ($sock === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client) {
                stream_set_blocking($client, false);
                $clients[(int)$client] = ['socket' => $client, 'handshake' => false];
            }
            continue;
        }

        $id = (int)$sock;
        $data = @fread($sock, 2048);
        if ($data === '' || $data === false) {
            @fclose($sock);
            unset($clients[$id]);
            continue;
        }

        if (!$clients[$id]['handshake']) {
            if (performHandshake($sock, $data)) {
                $clients[$id]['handshake'] = true;
            } else {
                @fclose($sock);
                unset($clients[$id]);
            }
        }
    }

    $events = readNewEvents($queueFile, $lastReadPos);
    if (!empty($events)) {
        foreach ($events as $event) {
            $message = wsEncode(json_encode($event, JSON_UNESCAPED_UNICODE));
            foreach ($clients as $id => $clientInfo) {
                if (!$clientInfo['handshake']) {
                    continue;
                }
                $ok = @fwrite($clientInfo['socket'], $message);
                if ($ok === false) {
                    @fclose($clientInfo['socket']);
                    unset($clients[$id]);
                }
            }
        }
    }
}

