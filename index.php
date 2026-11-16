<?php
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$ENV = file_exists(__DIR__ . '/.env.php') ? (require __DIR__ . '/.env.php') : [];

function cfg(string $key, $default = '')
{
    global $ENV;
    return $ENV[$key] ?? $default;
}

function uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}


class Supabase
{
    private string $url;
    private string $key;

    public function __construct()
    {
        $this->url = rtrim((string) cfg('SUPABASE_URL'), '/');
        $this->key = (string) cfg('SUPABASE_ANON_KEY');
        if ($this->url === '' || $this->key === '') {
            throw new RuntimeException('SUPABASE_URL ou SUPABASE_ANON_KEY ausentes no .env.php.');
        }
    }

    public function call(string $method, string $path, ?array $body = null, ?string $token = null, bool $minimal = false): array
    {
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->key,
            'Authorization: Bearer ' . ($token ?: $this->key),
        ];
        if ($minimal) {
            $headers[] = 'Prefer: return=minimal';
        }

        $ch = curl_init($this->url . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body === [] ? '{}' : json_encode($body));
        }
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Supabase cURL: $err");
        }
        return ['code' => $code, 'data' => json_decode((string) $res, true)];
    }

    public static function errorMessage(array $r): string
    {
        $d = $r['data'];
        if (is_array($d)) {
            $m = (string) ($d['msg'] ?? $d['error_description'] ?? $d['message'] ?? 'Erro desconhecido');
            $map = [
                'Invalid login credentials'        => 'E-mail ou senha incorretos.',
                'should be different from the old' => 'A nova senha deve ser diferente da atual.',
                'at least'                         => 'A senha é muito curta (mínimo 6 caracteres).',
                'rate limit'                       => 'Muitos e-mails enviados. Aguarde um pouco e tente de novo.',
                'already registered'               => 'Esse e-mail já tem conta. Use "Entrar".',
                'expired'                          => 'O link expirou. Peça um novo.',
                'Auth session missing'             => 'Sessão inválida. Peça um novo link.',
                'Email not confirmed'              => 'Confirme seu e-mail antes de entrar.',
            ];
            foreach ($map as $needle => $pt) {
                if (stripos($m, $needle) !== false) {
                    return $pt;
                }
            }
            return $m;
        }
        return 'Erro HTTP ' . $r['code'];
    }
}

function authStore(array $d): void
{
    $_SESSION['auth'] = [
        'access'  => $d['access_token'],
        'refresh' => $d['refresh_token'],
        'exp'     => time() + (int) ($d['expires_in'] ?? 3600),
        'uid'     => $d['user']['id'],
        'email'   => $d['user']['email'] ?? '',
    ];
}

function accessToken(Supabase $sb): ?string
{
    $a = $_SESSION['auth'] ?? null;
    if (!$a) {
        return null;
    }
    if ($a['exp'] - 60 < time()) {
        $r = $sb->call('POST', '/auth/v1/token?grant_type=refresh_token', ['refresh_token' => $a['refresh']]);
        if ($r['code'] !== 200 || empty($r['data']['access_token'])) {
            unset($_SESSION['auth']);
            return null;
        }
        authStore($r['data']);
        $a = $_SESSION['auth'];
    }
    return $a['access'];
}


class GeminiService
{
    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/';
    private string $cacheFile;

    private const SYSTEM_PROMPT =
        "Você é o assistente de IA deste sistema web (um chat com login de usuário). " .
        "As conversas de cada usuário ficam salvas no histórico da conta dele (aparecem na barra lateral) " .
        "e você recebe as mensagens anteriores da conversa atual como contexto, então pode lembrar do que foi dito nela. " .
        "Você não tem acesso a outras conversas. " .
        "Se perguntarem se a conversa é salva, responda que sim: fica no histórico da conta e pode ser reaberta depois. " .
        "Responda sempre em português do Brasil. Seja direto e conciso: respostas curtas por padrão, " .
        "e mais detalhadas só quando o usuário pedir. Pode usar Markdown simples (negrito, listas).";

    public function __construct()
    {
        $this->apiKey = (string) cfg('GEMINI_API_KEY');
        if ($this->apiKey === '' || str_starts_with($this->apiKey, 'COLE_AQUI')) {
            throw new RuntimeException('GEMINI_API_KEY não configurada no .env.php.');
        }
        $this->cacheFile = __DIR__ . '/.modelo_ok';
    }

    private function request(string $path, ?array $payload = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey],
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        return ['code' => $code, 'error' => $err, 'data' => json_decode((string) $res, true) ?? []];
    }

    private function callModel(string $model, array &$payload, array &$logs): ?string
    {
        $maxAttempts = 2;
        for ($i = 1; $i <= $maxAttempts; $i++) {
            $t = microtime(true);
            $r = $this->request("models/{$model}:generateContent", $payload);
            $ms = (int) ((microtime(true) - $t) * 1000);

            if ($r['error']) {
                $logs[] = "{$model} tentativa {$i}: cURL {$r['error']} ({$ms}ms)";
                continue;
            }

            
            $text = null;
            foreach ($r['data']['candidates'][0]['content']['parts'] ?? [] as $p) {
                if (isset($p['text']) && empty($p['thought'])) {
                    $text = ($text ?? '') . $p['text'];
                }
            }
            if ($r['code'] === 200 && $text !== null) {
                $logs[] = "{$model}: OK ({$ms}ms)";
                return $text;
            }

            $msg = $r['data']['error']['message'] ?? 'Resposta sem texto (possível bloqueio de segurança).';
            $logs[] = "{$model} tentativa {$i}: HTTP {$r['code']} - {$msg} ({$ms}ms)";

            
            if ($r['code'] === 400 && isset($payload['generationConfig'])) {
                unset($payload['generationConfig']);
                $logs[] = 'Removi generationConfig (thinking) e vou tentar de novo.';
                $i--;
                continue;
            }
            if (in_array($r['code'], [429, 500, 503], true)) {
                sleep(1);
                continue;
            }
            if (in_array($r['code'], [400, 401, 403], true)) {
                throw new RuntimeException("Erro de autenticação/requisição no Gemini:\n- " . implode("\n- ", $logs));
            }
            return null;
        }
        return null;
    }

    private function discoverModels(array &$logs): array
    {
        $r = $this->request('models?pageSize=100');
        $found = [];
        foreach ($r['data']['models'] ?? [] as $m) {
            $name = str_replace('models/', '', $m['name'] ?? '');
            if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true) && stripos($name, 'flash') !== false) {
                $found[] = $name;
            }
        }
        $logs[] = 'Modelos descobertos: ' . ($found ? implode(', ', $found) : 'nenhum');
        return $found;
    }

   
    public function generate(array $contents): array
    {
        $logs = [];
        $payload = [
            'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
            'contents'           => $contents,
        ];

        
        $thinking = (string) cfg('GEMINI_THINKING', 'low');
        if ($thinking !== 'off' && $thinking !== '') {
            $payload['generationConfig'] = ['thinkingConfig' => ['thinkingLevel' => $thinking]];
        }

        $models = (array) cfg('GEMINI_MODELS', ['gemini-flash-latest']);
        $cached = is_file($this->cacheFile) ? trim((string) file_get_contents($this->cacheFile)) : '';
        if ($cached !== '') {
            array_unshift($models, $cached);
        }
        $models = array_values(array_unique($models));

        $tried = [];
        foreach ($models as $model) {
            $tried[] = $model;
            $text = $this->callModel($model, $payload, $logs);
            if ($text !== null) {
                @file_put_contents($this->cacheFile, $model);
                return ['text' => $text, 'model' => $model, 'logs' => $logs];
            }
        }

        foreach ($this->discoverModels($logs) as $model) {
            if (in_array($model, $tried, true)) {
                continue;
            }
            $tried[] = $model;
            $text = $this->callModel($model, $payload, $logs);
            if ($text !== null) {
                @file_put_contents($this->cacheFile, $model);
                return ['text' => $text, 'model' => $model, 'logs' => $logs];
            }
            if (count($tried) >= 5) {
                break;
            }
        }
        throw new RuntimeException("Falha ao comunicar com o Gemini. Detalhes:\n- " . implode("\n- ", $logs));
    }
}


if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    set_time_limit(120);
    header('Content-Type: application/json; charset=utf-8');

    $in  = json_decode(file_get_contents('php://input'), true) ?? [];
    $act = (string) ($in['action'] ?? '');

    try {
        $sb = new Supabase();

        if ($act === 'register' || $act === 'login') {
            $email = trim((string) ($in['email'] ?? ''));
            $pass  = (string) ($in['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Informe um e-mail válido e uma senha de pelo menos 6 caracteres.']);
                exit;
            }
            $path = $act === 'register' ? '/auth/v1/signup' : '/auth/v1/token?grant_type=password';
            $r = $sb->call('POST', $path, ['email' => $email, 'password' => $pass]);

            if ($r['code'] === 200 && !empty($r['data']['access_token'])) {
                session_regenerate_id(true);
                authStore($r['data']);
                echo json_encode(['success' => true, 'email' => $_SESSION['auth']['email']]);
            } elseif ($act === 'register' && $r['code'] === 200) {
                echo json_encode(['success' => true, 'pending' => 'Conta criada! Confirme o e-mail que enviamos e depois faça login.']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => Supabase::errorMessage($r)]);
            }
            exit;
        }

        if ($act === 'forgot') {
            $email = trim((string) ($in['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Informe um e-mail válido.']);
                exit;
            }
            $r = $sb->call('POST', '/auth/v1/recover', ['email' => $email]);
            if ($r['code'] >= 300) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => Supabase::errorMessage($r)]);
                exit;
            }
 
            echo json_encode(['success' => true, 'pending' => 'Se esse e-mail tiver uma conta, enviamos um link para redefinir a senha. Confira também o spam.']);
            exit;
        }

        if ($act === 'reset_password') {
            $tk = (string) ($in['access_token'] ?? '');
            $pw = (string) ($in['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $tk) || strlen($pw) < 6) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Link inválido ou senha muito curta (mínimo 6 caracteres).']);
                exit;
            }
            $r = $sb->call('PUT', '/auth/v1/user', ['password' => $pw], $tk);
            if ($r['code'] !== 200) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => Supabase::errorMessage($r)]);
                exit;
            }
            echo json_encode(['success' => true]);
            exit;
        }

        if ($act === 'me') {
            echo json_encode(['success' => true, 'email' => accessToken($sb) ? $_SESSION['auth']['email'] : null]);
            exit;
        }

        if ($act === 'logout') {
            $_SESSION = [];
            session_destroy();
            echo json_encode(['success' => true]);
            exit;
        }

        
        $token = accessToken($sb);
        if (!$token) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Sessão expirada. Faça login novamente.', 'auth' => false]);
            exit;
        }
        $uid = $_SESSION['auth']['uid'];

        if ($act === 'conversations') {
            $r = $sb->call('GET', '/rest/v1/conversas?select=id,titulo&order=created_at.desc&limit=30', null, $token);
            echo json_encode(['success' => true, 'items' => is_array($r['data']) ? $r['data'] : []]);
            exit;
        }

        if ($act === 'messages') {
            $cid = (string) ($in['conversation_id'] ?? '');
            if (!preg_match('/^[0-9a-f-]{36}$/i', $cid)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'conversation_id inválido.']);
                exit;
            }
            $r = $sb->call('GET', "/rest/v1/mensagens?conversa_id=eq.$cid&select=remetente,conteudo&order=created_at.asc", null, $token);
            echo json_encode(['success' => true, 'items' => is_array($r['data']) ? $r['data'] : []]);
            exit;
        }

        if ($act === 'change_password') {
            $new = (string) ($in['password'] ?? '');
            if (strlen($new) < 6) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'A nova senha precisa ter pelo menos 6 caracteres.']);
                exit;
            }
            $chk = $sb->call('POST', '/auth/v1/token?grant_type=password', [
                'email'    => $_SESSION['auth']['email'],
                'password' => (string) ($in['current'] ?? ''),
            ]);
            if ($chk['code'] !== 200) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Senha atual incorreta.']);
                exit;
            }
            $r = $sb->call('PUT', '/auth/v1/user', ['password' => $new], $token);
            if ($r['code'] !== 200) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => Supabase::errorMessage($r)]);
                exit;
            }
            echo json_encode(['success' => true]);
            exit;
        }

        if ($act === 'delete_account') {

            $chk = $sb->call('POST', '/auth/v1/token?grant_type=password', [
                'email'    => $_SESSION['auth']['email'],
                'password' => (string) ($in['password'] ?? ''),
            ]);
            if ($chk['code'] !== 200) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Senha incorreta.']);
                exit;
            }
         
            $r = $sb->call('POST', '/rest/v1/rpc/excluir_minha_conta', [], $token);
            if ($r['code'] >= 300) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Não foi possível excluir: ' . Supabase::errorMessage($r) . ' (você rodou o delete_account.sql no Supabase?)']);
                exit;
            }
            $_SESSION = [];
            session_destroy();
            echo json_encode(['success' => true]);
            exit;
        }

        if ($act === 'delete') {
            $cid = (string) ($in['conversation_id'] ?? '');
            if (!preg_match('/^[0-9a-f-]{36}$/i', $cid)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'conversation_id inválido.']);
                exit;
            }
            // O RLS garante que só dá para apagar conversas do próprio usuário
            $r = $sb->call('DELETE', "/rest/v1/conversas?id=eq.$cid", null, $token, true);
            if ($r['code'] >= 300) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => Supabase::errorMessage($r)]);
                exit;
            }
            echo json_encode(['success' => true]);
            exit;
        }

        if ($act === 'chat') {
            $prompt = $in['prompt'] ?? null;
            if (!is_string($prompt) || trim($prompt) === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "[VALIDAÇÃO] O campo 'prompt' está vazio ou é inválido."]);
                exit;
            }
            $cid   = (string) ($in['conversation_id'] ?? '');
            $isNew = $cid === '';
            if ($isNew) {
                $cid = uuid4();
            } elseif (!preg_match('/^[0-9a-f-]{36}$/i', $cid)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'conversation_id inválido.']);
                exit;
            }

          
            $contents = [];
            if (!$isNew) {
                $h = $sb->call('GET', "/rest/v1/mensagens?conversa_id=eq.$cid&select=remetente,conteudo&order=created_at.desc&limit=20", null, $token);
                foreach (array_reverse(is_array($h['data']) ? $h['data'] : []) as $m) {
                    $contents[] = [
                        'role'  => $m['remetente'] === 'assistant' ? 'model' : 'user',
                        'parts' => [['text' => $m['conteudo']]],
                    ];
                }
            }
            $contents[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

            $t0   = microtime(true);
            $resp = (new GeminiService())->generate($contents);
            $ms   = (int) ((microtime(true) - $t0) * 1000);

            
            $dbErr = null;
            try {
                if ($isNew) {
                    $r = $sb->call('POST', '/rest/v1/conversas', [
                        'id' => $cid, 'usuario_id' => $uid, 'titulo' => mb_strimwidth($prompt, 0, 40, '...'),
                    ], $token, true);
                    if ($r['code'] >= 300) {
                        throw new RuntimeException('conversas: ' . Supabase::errorMessage($r));
                    }
                }
                $r = $sb->call('POST', '/rest/v1/mensagens', [
                    ['conversa_id' => $cid, 'remetente' => 'user',      'conteudo' => $prompt],
                    ['conversa_id' => $cid, 'remetente' => 'assistant', 'conteudo' => $resp['text']],
                ], $token, true);
                if ($r['code'] >= 300) {
                    throw new RuntimeException('mensagens: ' . Supabase::errorMessage($r));
                }
            } catch (Throwable $e) {
                $dbErr = $e->getMessage();
            }

            echo json_encode([
                'success'         => true,
                'conversation_id' => $cid,
                'result'          => $resp['text'],
                'model'           => $resp['model'],
                'ms'              => $ms,
                'logs'            => $resp['logs'],
                'db_error'        => $dbErr,
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação desconhecida.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistente IA - Lab PHP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; }
        .app { max-width: 1000px; margin: 30px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.1); overflow: hidden; }
        .app-header { background: #0d6efd; color: #fff; padding: 15px 20px; font-size: 1.15rem; font-weight: bold; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .layout { display: flex; }
        .sidebar { width: 240px; border-right: 1px solid #dee2e6; background: #f8f9fa; height: 520px; overflow-y: auto; padding: 10px; }
        .sidebar .conv { display: block; width: 100%; text-align: left; border: 0; background: none; padding: 8px 10px; border-radius: 8px; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conv-row { display: flex; align-items: center; border-radius: 8px; }
        .conv-row:hover, .conv-row.active { background: #e2e8f0; }
        .conv-row .conv { flex: 1; width: auto; min-width: 0; }
        .conv-del { border: 0; background: none; color: #6c757d; padding: 4px 8px; margin-right: 4px; border-radius: 6px; font-size: .85rem; visibility: hidden; }
        .conv-row:hover .conv-del, .conv-row.active .conv-del { visibility: visible; }
        .conv-del:hover { color: #dc3545; background: #fde2e4; }
        @media (hover: none) { .conv-del { visibility: visible; } }
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .chat-box { height: 460px; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; }
        .message { max-width: 80%; padding: 10px 15px; border-radius: 18px; line-height: 1.45; word-wrap: break-word; }
        .message.user { align-self: flex-end; background: #0d6efd; color: #fff; border-bottom-right-radius: 4px; white-space: pre-wrap; }
        .message.assistant { align-self: flex-start; background: #e9ecef; color: #212529; border-bottom-left-radius: 4px; }
        .message.error, .message.warn { white-space: pre-wrap; }
        .message.error { align-self: flex-start; background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; font-family: monospace; font-size: .88rem; }
        .message.warn { align-self: flex-start; background: #fff3cd; color: #664d03; border: 1px solid #ffecb5; font-size: .85rem; }
        .message.typing { align-self: flex-start; background: #e9ecef; color: #6c757d; font-style: italic; }
        .message.assistant p { margin: 0 0 .5rem; }
        .message.assistant ul, .message.assistant ol { margin: 0 0 .5rem; padding-left: 1.3rem; }
        .message.assistant .md-h { font-weight: 700; margin: .6rem 0 .3rem; }
        .message.assistant pre { background: #212529; color: #f8f9fa; padding: 10px; border-radius: 8px; overflow-x: auto; margin: 0 0 .5rem; }
        .message.assistant code { font-size: .88em; }
        .message.assistant :last-child { margin-bottom: 0; }
        .meta { align-self: flex-start; font-size: .75rem; color: #6c757d; margin-top: -8px; }
        .chat-footer { padding: 15px; background: #f8f9fa; border-top: 1px solid #dee2e6; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; z-index: 1000; }
        .modal-card { background: #fff; border-radius: 12px; padding: 20px; width: 90%; max-width: 400px; }
        .auth-box { max-width: 380px; margin: 40px auto; padding: 0 20px 30px; }
        @media (max-width: 700px) { .sidebar { display: none; } }
    </style>
</head>
<body>
<div class="app">
    <div class="app-header">
        <span>🤖 Assistente Inteligente</span>
        <span id="userBox" class="d-none">
            <small id="userEmail" class="me-2"></small>
            <button class="btn btn-sm btn-outline-light me-1" id="changePwBtn">Alterar senha</button>
            <button class="btn btn-sm btn-outline-light me-1" id="deleteAccBtn">Excluir conta</button>
            <button class="btn btn-sm btn-light" id="logoutBtn">Sair</button>
        </span>
    </div>


    <div id="authView" class="auth-box d-none">
        <h5 class="mb-3" id="authTitle">Entrar</h5>
        <form id="authForm" class="d-grid gap-2">
            <input type="email" id="email" class="form-control" placeholder="E-mail" required autocomplete="email">
            <input type="password" id="password" class="form-control" placeholder="Senha (mín. 6 caracteres)" required minlength="6" autocomplete="current-password">
            <button type="submit" class="btn btn-primary" id="authBtn">Entrar</button>
        </form>
        <div id="authMsg" class="mt-2 small"></div>
        <button class="btn btn-link p-0 mt-2" id="toggleAuth">Não tem conta? Criar conta</button>
        <button class="btn btn-link p-0 mt-2 d-block" id="forgotBtn">Esqueci minha senha</button>
    </div>

 
    <div id="resetView" class="auth-box d-none">
        <h5 class="mb-3">Definir nova senha</h5>
        <form id="resetForm" class="d-grid gap-2">
            <input type="password" id="resetPw" class="form-control" placeholder="Nova senha (mín. 6 caracteres)" required minlength="6" autocomplete="new-password">
            <input type="password" id="resetPw2" class="form-control" placeholder="Repita a nova senha" required minlength="6" autocomplete="new-password">
            <button type="submit" class="btn btn-primary" id="resetBtn">Salvar nova senha</button>
        </form>
        <div id="resetMsg" class="mt-2 small text-danger"></div>
    </div>


    <div id="chatView" class="layout d-none">
        <div class="sidebar">
            <button class="btn btn-outline-primary btn-sm w-100 mb-2" id="newChatBtn">+ Nova conversa</button>
            <div id="convList"></div>
        </div>
        <div class="main">
            <div class="chat-box" id="chatBox"></div>
            <div class="chat-footer">
                <form id="chatForm" class="d-flex gap-2">
                    <input type="text" id="promptInput" class="form-control" placeholder="Digite sua mensagem..." required autocomplete="off">
                    <button type="submit" class="btn btn-primary" id="sendBtn">Enviar</button>
                </form>
            </div>
        </div>
    </div>
</div>


<div id="pwModal" class="modal-overlay d-none">
    <div class="modal-card">
        <h5>Alterar senha</h5>
        <input type="password" id="pwCurrent" class="form-control mb-2" placeholder="Senha atual" autocomplete="current-password">
        <input type="password" id="pwNew" class="form-control mb-2" placeholder="Nova senha (mín. 6 caracteres)" autocomplete="new-password">
        <input type="password" id="pwNew2" class="form-control mb-2" placeholder="Repita a nova senha" autocomplete="new-password">
        <div id="pwMsg" class="small mb-2"></div>
        <div class="d-flex gap-2 justify-content-end">
            <button class="btn btn-light" id="pwCancel">Cancelar</button>
            <button class="btn btn-primary" id="pwConfirm">Salvar</button>
        </div>
    </div>
</div>

<div id="accModal" class="modal-overlay d-none">
    <div class="modal-card">
        <h5 class="text-danger">Excluir conta</h5>
        <p class="small">Isso apaga sua conta e <strong>todas as suas conversas</strong> de forma permanente. Não dá para desfazer. Digite sua senha para confirmar.</p>
        <input type="password" id="accPassword" class="form-control mb-2" placeholder="Sua senha" autocomplete="current-password">
        <div id="accMsg" class="small text-danger mb-2"></div>
        <div class="d-flex gap-2 justify-content-end">
            <button class="btn btn-light" id="accCancel">Cancelar</button>
            <button class="btn btn-danger" id="accConfirm">Excluir minha conta</button>
        </div>
    </div>
</div>

<script>
const $ = id => document.getElementById(id);
let mode = 'login';
let conversationId = null;


function md(src) {
    const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const inline = s => esc(s)
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

    const out = [];
    let list = null, inCode = false, buf = [];
    const closeList = () => { if (list) { out.push('</' + list + '>'); list = null; } };
    const openList = tag => { if (list !== tag) { closeList(); out.push('<' + tag + '>'); list = tag; } };

    for (const line of src.split('\n')) {
        if (line.trim().startsWith('```')) {
            if (inCode) { out.push('<pre><code>' + esc(buf.join('\n')) + '</code></pre>'); buf = []; inCode = false; }
            else { closeList(); inCode = true; }
            continue;
        }
        if (inCode) { buf.push(line); continue; }

        let m;
        if ((m = line.match(/^\s*[*-]\s+(.*)/))) { openList('ul'); out.push('<li>' + inline(m[1]) + '</li>'); }
        else if ((m = line.match(/^\s*\d+[.)]\s+(.*)/))) { openList('ol'); out.push('<li>' + inline(m[1]) + '</li>'); }
        else if ((m = line.match(/^#{1,6}\s+(.*)/))) { closeList(); out.push('<div class="md-h">' + inline(m[1]) + '</div>'); }
        else if (/^\s*-{3,}\s*$/.test(line)) { closeList(); out.push('<hr>'); }
        else if (line.trim() === '') { closeList(); }
        else { closeList(); out.push('<p>' + inline(line) + '</p>'); }
    }
    if (inCode) out.push('<pre><code>' + esc(buf.join('\n')) + '</code></pre>');
    closeList();
    return out.join('');
}

async function api(action, data = {}) {
    const res = await fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...data })
    });
    const raw = await res.text();
    try { return JSON.parse(raw); }
    catch (_) { return { success: false, error: '[RESPOSTA NÃO-JSON]\n' + raw.slice(0, 500) }; }
}

function addMsg(text, cls) {
    const d = document.createElement('div');
    d.className = 'message ' + cls;
    if (cls === 'assistant') d.innerHTML = md(text);
    else d.textContent = text;
    $('chatBox').appendChild(d);
    $('chatBox').scrollTop = $('chatBox').scrollHeight;
    return d;
}
function addMeta(text) {
    const d = document.createElement('div');
    d.className = 'meta';
    d.textContent = text;
    $('chatBox').appendChild(d);
    $('chatBox').scrollTop = $('chatBox').scrollHeight;
}

function showAuth() {
    $('resetView').classList.add('d-none');
    $('authView').classList.remove('d-none');
    $('chatView').classList.add('d-none');
    $('userBox').classList.add('d-none');
}
function showChat(email) {
    $('authView').classList.add('d-none');
    $('chatView').classList.remove('d-none');
    $('userBox').classList.remove('d-none');
    $('userEmail').textContent = email;
    newChat();
    loadConversations();
}

function newChat() {
    conversationId = null;
    $('chatBox').innerHTML = '';
    addMsg('Olá! Eu lembro do que conversamos nesta conversa. Como posso ajudar?', 'assistant');
    document.querySelectorAll('.conv-row').forEach(b => b.classList.remove('active'));
}

async function loadConversations() {
    const r = await api('conversations');
    const list = $('convList');
    list.innerHTML = '';
    if (!r.success) return;
    r.items.forEach(c => {
        const row = document.createElement('div');
        row.className = 'conv-row' + (c.id === conversationId ? ' active' : '');

        const b = document.createElement('button');
        b.className = 'conv';
        b.textContent = c.titulo || 'Sem título';
        b.onclick = () => openConversation(c.id);

        const del = document.createElement('button');
        del.className = 'conv-del';
        del.title = 'Excluir conversa';
        del.textContent = '🗑';
        del.onclick = e => { e.stopPropagation(); deleteConversation(c.id, c.titulo); };

        row.append(b, del);
        list.appendChild(row);
    });
}

async function deleteConversation(id, titulo) {
    if (!confirm('Excluir a conversa "' + (titulo || 'Sem título') + '"? Isso não pode ser desfeito.')) return;
    const r = await api('delete', { conversation_id: id });
    if (!r.success) { alert(r.error || 'Não foi possível excluir.'); return; }
    if (id === conversationId) newChat();
    loadConversations();
}

async function openConversation(id) {
    conversationId = id;
    const r = await api('messages', { conversation_id: id });
    $('chatBox').innerHTML = '';
    if (!r.success) { addMsg(r.error || 'Erro ao carregar.', 'error'); return; }
    r.items.forEach(m => addMsg(m.conteudo, m.remetente === 'assistant' ? 'assistant' : 'user'));
    loadConversations();
}

function setMode(m) {
    mode = m;
    const titles = { login: 'Entrar', register: 'Criar conta', forgot: 'Recuperar senha' };
    const btns   = { login: 'Entrar', register: 'Criar conta', forgot: 'Enviar link por e-mail' };
    const toggle = { login: 'Não tem conta? Criar conta', register: 'Já tem conta? Entrar', forgot: 'Voltar para o login' };
    $('authTitle').textContent = titles[m];
    $('authBtn').textContent = btns[m];
    $('toggleAuth').textContent = toggle[m];
    $('password').classList.toggle('d-none', m === 'forgot');
    $('password').required = m !== 'forgot';
    $('password').autocomplete = m === 'register' ? 'new-password' : 'current-password';
    $('forgotBtn').classList.toggle('d-none', m !== 'login');
    $('authMsg').textContent = '';
}
$('toggleAuth').onclick = () => setMode(mode === 'login' ? 'register' : 'login');
$('forgotBtn').onclick = () => setMode('forgot');

$('authForm').addEventListener('submit', async e => {
    e.preventDefault();
    $('authBtn').disabled = true;
    const r = mode === 'forgot'
        ? await api('forgot', { email: $('email').value })
        : await api(mode, { email: $('email').value, password: $('password').value });
    $('authBtn').disabled = false;
    if (r.success && r.email) { $('password').value = ''; $('authMsg').textContent = ''; showChat(r.email); }
    else if (r.success && r.pending) { $('authMsg').className = 'mt-2 small text-success'; $('authMsg').textContent = r.pending; }
    else { $('authMsg').className = 'mt-2 small text-danger'; $('authMsg').textContent = r.error || 'Falha.'; }
});


$('changePwBtn').onclick = () => {
    ['pwCurrent', 'pwNew', 'pwNew2'].forEach(id => $(id).value = '');
    $('pwMsg').textContent = '';
    $('pwModal').classList.remove('d-none');
    $('pwCurrent').focus();
};
$('pwCancel').onclick = () => $('pwModal').classList.add('d-none');
$('pwConfirm').onclick = async () => {
    const setMsg = (t, ok) => { $('pwMsg').className = 'small mb-2 ' + (ok ? 'text-success' : 'text-danger'); $('pwMsg').textContent = t; };
    const cur = $('pwCurrent').value, n1 = $('pwNew').value, n2 = $('pwNew2').value;
    if (!cur || !n1) { setMsg('Preencha todos os campos.', false); return; }
    if (n1.length < 6) { setMsg('A nova senha precisa ter pelo menos 6 caracteres.', false); return; }
    if (n1 !== n2) { setMsg('As senhas novas não são iguais.', false); return; }
    $('pwConfirm').disabled = true;
    const r = await api('change_password', { current: cur, password: n1 });
    $('pwConfirm').disabled = false;
    if (!r.success) { setMsg(r.error || 'Não foi possível alterar.', false); return; }
    setMsg('Senha alterada com sucesso!', true);
    setTimeout(() => $('pwModal').classList.add('d-none'), 1300);
};


let recoveryToken = null;
function showReset() {
    $('authView').classList.add('d-none');
    $('chatView').classList.add('d-none');
    $('userBox').classList.add('d-none');
    $('resetView').classList.remove('d-none');
}
$('resetForm').addEventListener('submit', async e => {
    e.preventDefault();
    const p1 = $('resetPw').value, p2 = $('resetPw2').value;
    if (p1 !== p2) { $('resetMsg').textContent = 'As senhas não são iguais.'; return; }
    $('resetBtn').disabled = true;
    const r = await api('reset_password', { access_token: recoveryToken, password: p1 });
    $('resetBtn').disabled = false;
    if (!r.success) { $('resetMsg').textContent = r.error || 'Não foi possível redefinir.'; return; }
    recoveryToken = null;
    $('resetPw').value = ''; $('resetPw2').value = ''; $('resetMsg').textContent = '';
    showAuth();
    setMode('login');
    $('authMsg').className = 'mt-2 small text-success';
    $('authMsg').textContent = 'Senha alterada! Entre com a nova senha.';
});

$('deleteAccBtn').onclick = () => {
    $('accPassword').value = '';
    $('accMsg').textContent = '';
    $('accModal').classList.remove('d-none');
    $('accPassword').focus();
};
$('accCancel').onclick = () => $('accModal').classList.add('d-none');
$('accPassword').addEventListener('keydown', e => { if (e.key === 'Enter') $('accConfirm').click(); });
$('accConfirm').onclick = async () => {
    const pw = $('accPassword').value;
    if (!pw) { $('accMsg').textContent = 'Digite sua senha.'; return; }
    $('accConfirm').disabled = true;
    const r = await api('delete_account', { password: pw });
    $('accConfirm').disabled = false;
    if (!r.success) { $('accMsg').textContent = r.error || 'Não foi possível excluir.'; return; }
    $('accModal').classList.add('d-none');
    showAuth();
    $('authMsg').className = 'mt-2 small text-success';
    $('authMsg').textContent = 'Conta excluída com sucesso.';
};

$('logoutBtn').onclick = async () => { await api('logout'); showAuth(); };
$('newChatBtn').onclick = newChat;

$('chatForm').addEventListener('submit', async e => {
    e.preventDefault();
    const prompt = $('promptInput').value.trim();
    if (!prompt) return;

    addMsg(prompt, 'user');
    $('promptInput').value = '';
    $('sendBtn').disabled = true;
    $('sendBtn').textContent = 'Aguarde...';
    const typing = addMsg('Pensando…', 'typing');

    try {
        const r = await api('chat', { prompt, conversation_id: conversationId });
        typing.remove();
        if (r.auth === false) { showAuth(); return; }
        if (r.success) {
            const isNew = !conversationId;
            conversationId = r.conversation_id;
            addMsg(r.result, 'assistant');
            addMeta(`${r.model} · ${(r.ms / 1000).toFixed(1)}s`);
            if (r.db_error) addMsg('⚠️ Resposta gerada, mas não salvei no Supabase:\n' + r.db_error, 'warn');
            console.log('Logs Gemini:', r.logs);
            if (isNew) loadConversations();
        } else {
            addMsg('🔍 DIAGNÓSTICO DE ERRO:\n\n' + (r.error || 'Falha desconhecida.'), 'error');
        }
    } catch (err) {
        typing.remove();
        addMsg('🔍 DIAGNÓSTICO DE ERRO:\n\n[ERRO DE REDE] ' + err.message, 'error');
    } finally {
        $('sendBtn').disabled = false;
        $('sendBtn').textContent = 'Enviar';
        $('promptInput').focus();
    }
});

(async () => {
    
    const hp = new URLSearchParams(location.hash.slice(1));
    if (hp.get('type') === 'recovery' && hp.get('access_token')) {
        recoveryToken = hp.get('access_token');
        history.replaceState(null, '', location.pathname + location.search); // tira o token da barra de endereço
        showReset();
        return;
    }
    if (hp.get('error')) {
        history.replaceState(null, '', location.pathname + location.search);
        showAuth();
        setMode('login');
        $('authMsg').className = 'mt-2 small text-danger';
        $('authMsg').textContent = 'O link é inválido ou expirou. Use "Esqueci minha senha" para pedir um novo.';
        return;
    }

    const r = await api('me');
    if (r.success && r.email) showChat(r.email); else showAuth();
})();
</script>
</body>
</html>
