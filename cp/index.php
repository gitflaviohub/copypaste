<?php
/*
 * CP — Copiar & Colar
 * Lista pública de textos com botão "Copiar" e QR code.
 * Criar, editar e apagar textos exige um PIN de 6 dígitos.
 * Os dados ficam na pasta "dados/" (ficheiros .php protegidos), sem base de dados.
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Lisbon');

const DIR_DADOS      = __DIR__ . '/dados';
const F_TEXTOS       = DIR_DADOS . '/textos.php';
const F_CONFIG       = DIR_DADOS . '/config.php';
const F_TENTATIVAS   = DIR_DADOS . '/tentativas.php';
const MAX_TENTATIVAS = 5;        // tentativas erradas antes de bloquear
const BLOQUEIO_SEG   = 15 * 60;  // bloqueio de 15 minutos
const SESSAO_SEG     = 60 * 60;  // sessão de edição expira após 1 hora sem uso
const MAX_TEXTO      = 20000;    // caracteres por texto
const CABECALHO      = "<?php exit; ?>\n"; // impede que os ficheiros de dados sejam lidos pelo browser

/* ---------- Armazenamento ---------- */

function ler(string $f, $padrao) {
    if (!is_file($f)) return $padrao;
    $c = (string) file_get_contents($f);
    if (strncmp($c, CABECALHO, strlen(CABECALHO)) === 0) $c = substr($c, strlen(CABECALHO));
    $d = json_decode($c, true);
    return is_array($d) ? $d : $padrao;
}

function gravar(string $f, array $d): void {
    if (!is_dir(DIR_DADOS)) mkdir(DIR_DADOS, 0755, true);
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (file_put_contents($f, CABECALHO . $json, LOCK_EX) === false) {
        http_response_code(500);
        exit('Erro: não foi possível gravar em "dados/". Verifique as permissões da pasta (755).');
    }
}

/* ---------- Sessão e segurança ---------- */

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('cp_sessao');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => dirname($_SERVER['SCRIPT_NAME']) ?: '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function admin(): bool {
    if (empty($_SESSION['admin'])) return false;
    if (time() - ($_SESSION['visto'] ?? 0) > SESSAO_SEG) { unset($_SESSION['admin']); return false; }
    $_SESSION['visto'] = time();
    return true;
}

function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0'; }

function bloqueado(): int {
    $t = ler(F_TENTATIVAS, []);
    $r = $t[ip()] ?? null;
    if ($r && $r['n'] >= MAX_TENTATIVAS) {
        $falta = $r['t'] + BLOQUEIO_SEG - time();
        if ($falta > 0) return $falta;
    }
    return 0;
}

function registarFalha(): void {
    $t = ler(F_TENTATIVAS, []);
    foreach ($t as $k => $r) if (time() - $r['t'] > BLOQUEIO_SEG) unset($t[$k]); // limpeza
    $r = $t[ip()] ?? ['n' => 0, 't' => time()];
    $r['n']++; $r['t'] = time();
    $t[ip()] = $r;
    gravar(F_TENTATIVAS, $t);
}

function limparFalhas(): void {
    $t = ler(F_TENTATIVAS, []);
    unset($t[ip()]);
    gravar(F_TENTATIVAS, $t);
}

function pinValido(string $p): bool { return (bool) preg_match('/^\d{6}$/', $p); }

function aviso(string $msg, string $tipo = 'ok'): void { $_SESSION['aviso'] = [$msg, $tipo]; }

function voltar(): void {
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- Ações (POST) ---------- */

$config = ler(F_CONFIG, []);
$temPin = !empty($config['pin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        aviso('A sessão expirou. Tente de novo.', 'erro'); voltar();
    }
    $acao = (string) ($_POST['acao'] ?? '');

    switch ($acao) {
        case 'definir_pin': // primeira utilização
            if ($temPin) voltar();
            $p1 = (string) ($_POST['pin'] ?? ''); $p2 = (string) ($_POST['pin2'] ?? '');
            if (!pinValido($p1))  { aviso('O PIN tem de ter exatamente 6 dígitos.', 'erro'); voltar(); }
            if ($p1 !== $p2)      { aviso('Os dois PINs não coincidem.', 'erro'); voltar(); }
            gravar(F_CONFIG, ['pin' => password_hash($p1, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['admin'] = true; $_SESSION['visto'] = time();
            aviso('PIN definido. Já pode criar textos.');
            voltar();

        case 'entrar':
            if ($falta = bloqueado()) {
                aviso('Demasiadas tentativas. Tente novamente dentro de ' . ceil($falta / 60) . ' min.', 'erro'); voltar();
            }
            $p = (string) ($_POST['pin'] ?? '');
            if ($temPin && pinValido($p) && password_verify($p, $config['pin'])) {
                limparFalhas();
                session_regenerate_id(true);
                $_SESSION['admin'] = true; $_SESSION['visto'] = time();
                aviso('Modo de edição ativo.');
            } else {
                registarFalha();
                aviso('PIN incorreto.', 'erro');
            }
            voltar();

        case 'sair':
            unset($_SESSION['admin']);
            session_regenerate_id(true);
            voltar();
    }

    // As restantes ações exigem o PIN
    if (!admin()) { aviso('Introduza o PIN para editar.', 'erro'); voltar(); }

    $textos = ler(F_TEXTOS, []);
    $id     = (string) ($_POST['id'] ?? '');
    $titulo = trim(mb_substr((string) ($_POST['titulo'] ?? ''), 0, 120));
    $texto  = str_replace("\r\n", "\n", (string) ($_POST['texto'] ?? ''));

    switch ($acao) {
        case 'criar':
            if (trim($texto) === '')              { aviso('O texto não pode estar vazio.', 'erro'); voltar(); }
            if (mb_strlen($texto) > MAX_TEXTO)    { aviso('Texto demasiado longo.', 'erro'); voltar(); }
            array_unshift($textos, [
                'id' => bin2hex(random_bytes(6)), 'titulo' => $titulo, 'texto' => $texto,
                'criado' => time(), 'alterado' => time(),
            ]);
            gravar(F_TEXTOS, $textos);
            aviso('Texto criado.');
            voltar();

        case 'editar':
            if (trim($texto) === '')              { aviso('O texto não pode estar vazio.', 'erro'); voltar(); }
            if (mb_strlen($texto) > MAX_TEXTO)    { aviso('Texto demasiado longo.', 'erro'); voltar(); }
            foreach ($textos as &$t) {
                if ($t['id'] === $id) { $t['titulo'] = $titulo; $t['texto'] = $texto; $t['alterado'] = time(); }
            }
            unset($t);
            gravar(F_TEXTOS, $textos);
            aviso('Texto atualizado.');
            voltar();

        case 'apagar':
            $textos = array_values(array_filter($textos, fn($t) => $t['id'] !== $id));
            gravar(F_TEXTOS, $textos);
            aviso('Texto apagado.');
            voltar();

        case 'mudar_pin':
            $atual = (string) ($_POST['pin_atual'] ?? '');
            $p1 = (string) ($_POST['pin'] ?? ''); $p2 = (string) ($_POST['pin2'] ?? '');
            if (!password_verify($atual, $config['pin'])) { aviso('O PIN atual está errado.', 'erro'); voltar(); }
            if (!pinValido($p1))  { aviso('O novo PIN tem de ter exatamente 6 dígitos.', 'erro'); voltar(); }
            if ($p1 !== $p2)      { aviso('Os dois PINs novos não coincidem.', 'erro'); voltar(); }
            gravar(F_CONFIG, ['pin' => password_hash($p1, PASSWORD_DEFAULT)]);
            aviso('PIN alterado.');
            voltar();
    }
    voltar();
}

/* ---------- Página ---------- */

$ehAdmin = admin();
$textos  = ler(F_TEXTOS, []);
$aviso   = $_SESSION['aviso'] ?? null; unset($_SESSION['aviso']);
$csrf    = h($_SESSION['csrf']);
$modo    = $_GET['modo'] ?? '';
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>CP · Copiar &amp; Colar</title>
<style>
:root {
  --bg: #f5f6f8; --card: #ffffff; --txt: #1d2330; --sub: #667085; --linha: #e3e6ec;
  --acc: #2563eb; --acc-txt: #ffffff; --ok: #15803d; --erro: #b91c1c; --campo: #fbfcfd;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #12151b; --card: #1b2029; --txt: #e7eaf0; --sub: #9aa3b2; --linha: #2b3240;
    --acc: #5b8cff; --acc-txt: #0b1020; --ok: #4ade80; --erro: #f87171; --campo: #151a22;
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--txt);
  font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
.wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 64px; }
header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
h1 { font-size: 22px; margin: 0; letter-spacing: -.01em; }
h1 small { color: var(--sub); font-weight: 400; font-size: 15px; }
h2 { font-size: 17px; margin: 0 0 12px; }
.card { background: var(--card); border: 1px solid var(--linha); border-radius: 12px; padding: 16px; margin-bottom: 14px; }
.titulo { font-weight: 600; margin-bottom: 6px; word-break: break-word; }
pre.texto { margin: 0 0 12px; white-space: pre-wrap; word-break: break-word; font: 15px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  background: var(--campo); border: 1px solid var(--linha); border-radius: 8px; padding: 10px 12px; max-height: 320px; overflow: auto; }
.acoes { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.meta { color: var(--sub); font-size: 13px; margin-left: auto; }
button, .btn { font: inherit; font-size: 14px; border-radius: 8px; padding: 7px 14px; cursor: pointer;
  border: 1px solid var(--linha); background: var(--card); color: var(--txt); text-decoration: none; display: inline-block; }
button.pri { background: var(--acc); border-color: var(--acc); color: var(--acc-txt); font-weight: 600; }
button.perigo { color: var(--erro); }
button:focus-visible, input:focus-visible, textarea:focus-visible { outline: 2px solid var(--acc); outline-offset: 2px; }
input, textarea { font: inherit; width: 100%; padding: 9px 11px; border: 1px solid var(--linha); border-radius: 8px;
  background: var(--campo); color: var(--txt); }
textarea { min-height: 110px; resize: vertical; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 15px; }
label { display: block; font-size: 14px; color: var(--sub); margin: 10px 0 4px; }
.pin { letter-spacing: .4em; font-size: 20px; text-align: center; max-width: 220px; }
.aviso { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; border: 1px solid; font-size: 15px; }
.aviso.ok { color: var(--ok); border-color: currentColor; }
.aviso.erro { color: var(--erro); border-color: currentColor; }
.vazio { text-align: center; color: var(--sub); padding: 40px 16px; }
.editar { display: none; margin-top: 12px; border-top: 1px dashed var(--linha); padding-top: 4px; }
.editar.aberto { display: block; }
details summary { cursor: pointer; color: var(--sub); font-size: 14px; }
dialog { border: 1px solid var(--linha); border-radius: 14px; background: var(--card); color: var(--txt); padding: 20px; max-width: 92vw; text-align: center; }
dialog::backdrop { background: rgba(0,0,0,.55); }
#qr-img { background: #fff; padding: 12px; border-radius: 8px; max-width: min(80vw, 360px); height: auto; image-rendering: pixelated; display: block; margin: 0 auto 14px; }
#qr-erro { color: var(--erro); margin: 0 0 14px; }
.copiado { background: var(--ok) !important; border-color: var(--ok) !important; color: #fff !important; }
</style>
</head>
<body>
<div class="wrap">

<header>
  <h1>CP <small>Copiar &amp; Colar</small></h1>
  <?php if ($ehAdmin): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button name="acao" value="sair">Sair da edição</button></form>
  <?php elseif ($temPin && $modo !== 'entrar'): ?>
    <a class="btn" href="?modo=entrar">Editar</a>
  <?php endif; ?>
</header>

<?php if ($aviso): ?><div class="aviso <?= h($aviso[1]) ?>" role="status"><?= h($aviso[0]) ?></div><?php endif; ?>

<?php if (!$temPin): ?>
  <div class="card">
    <h2>Primeira utilização: defina o PIN</h2>
    <p style="margin:0;color:var(--sub)">Este PIN de 6 dígitos vai proteger a criação, edição e remoção de textos.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <label for="p1">Novo PIN</label>
      <input class="pin" id="p1" name="pin" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" required>
      <label for="p2">Repetir PIN</label>
      <input class="pin" id="p2" name="pin2" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" required>
      <p><button class="pri" name="acao" value="definir_pin">Guardar PIN</button></p>
    </form>
  </div>
<?php elseif (!$ehAdmin && $modo === 'entrar'): ?>
  <div class="card">
    <h2>Introduza o PIN</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input class="pin" name="pin" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus aria-label="PIN de 6 dígitos">
      <p class="acoes"><button class="pri" name="acao" value="entrar">Entrar</button> <a class="btn" href="./">Cancelar</a></p>
    </form>
  </div>
<?php endif; ?>

<?php if ($ehAdmin): ?>
  <div class="card">
    <h2>Novo texto</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <label for="nt">Título (opcional)</label>
      <input id="nt" name="titulo" maxlength="120">
      <label for="nx">Texto</label>
      <textarea id="nx" name="texto" required maxlength="<?= MAX_TEXTO ?>"></textarea>
      <p class="acoes"><button class="pri" name="acao" value="criar">Adicionar</button></p>
    </form>
  </div>
<?php endif; ?>

<?php if (!$textos): ?>
  <div class="vazio"><?= $ehAdmin ? 'Ainda não há textos. Adicione o primeiro acima.' : 'Ainda não há textos.' ?></div>
<?php endif; ?>

<?php foreach ($textos as $t): $id = h($t['id']); ?>
  <div class="card">
    <?php if ($t['titulo'] !== ''): ?><div class="titulo"><?= h($t['titulo']) ?></div><?php endif; ?>
    <pre class="texto" id="t-<?= $id ?>"><?= h($t['texto']) ?></pre>
    <div class="acoes">
      <button class="pri" type="button" data-copiar="t-<?= $id ?>">Copiar</button>
      <button type="button" data-qr="t-<?= $id ?>" data-nome="<?= h($t['titulo'] ?: 'texto') ?>">QR code</button>
      <?php if ($ehAdmin): ?>
        <button type="button" data-abrir="e-<?= $id ?>">Editar</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Apagar este texto?')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="perigo" name="acao" value="apagar">Apagar</button>
        </form>
      <?php endif; ?>
      <span class="meta"><?= date('d/m/Y H:i', (int) $t['alterado']) ?></span>
    </div>
    <?php if ($ehAdmin): ?>
      <form method="post" class="editar" id="e-<?= $id ?>">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= $id ?>">
        <label>Título (opcional)</label>
        <input name="titulo" maxlength="120" value="<?= h($t['titulo']) ?>">
        <label>Texto</label>
        <textarea name="texto" required maxlength="<?= MAX_TEXTO ?>"><?= h($t['texto']) ?></textarea>
        <p class="acoes"><button class="pri" name="acao" value="editar">Guardar</button> <button type="button" data-abrir="e-<?= $id ?>">Cancelar</button></p>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($ehAdmin): ?>
  <details class="card">
    <summary>Mudar PIN</summary>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <label>PIN atual</label>
      <input class="pin" name="pin_atual" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" required>
      <label>Novo PIN</label>
      <input class="pin" name="pin" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" required>
      <label>Repetir novo PIN</label>
      <input class="pin" name="pin2" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" required>
      <p><button class="pri" name="acao" value="mudar_pin">Mudar PIN</button></p>
    </form>
  </details>
<?php endif; ?>

</div>

<dialog id="qr">
  <img id="qr-img" alt="QR code do texto">
  <p id="qr-erro" hidden></p>
  <div class="acoes" style="justify-content:center">
    <a class="btn" id="qr-down" download="qrcode.png">Descarregar</a>
    <button type="button" onclick="document.getElementById('qr').close()">Fechar</button>
  </div>
</dialog>

<script src="qrcode.js"></script>
<script>
(function () {
  // Copiar texto (com alternativa para HTTP sem clipboard API)
  function copiar(texto) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(texto);
    return new Promise(function (ok, falha) {
      var ta = document.createElement('textarea');
      ta.value = texto; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? ok() : falha(); } catch (e) { falha(e); }
      document.body.removeChild(ta);
    });
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button'); if (!b) return;

    if (b.dataset.copiar) {
      var txt = document.getElementById(b.dataset.copiar).textContent;
      copiar(txt).then(function () {
        b.textContent = 'Copiado ✓'; b.classList.add('copiado');
        setTimeout(function () { b.textContent = 'Copiar'; b.classList.remove('copiado'); }, 1600);
      }, function () { b.textContent = 'Erro ao copiar'; });
    }

    if (b.dataset.abrir) document.getElementById(b.dataset.abrir).classList.toggle('aberto');

    if (b.dataset.qr) {
      var texto = document.getElementById(b.dataset.qr).textContent;
      var img = document.getElementById('qr-img'), erro = document.getElementById('qr-erro'), down = document.getElementById('qr-down');
      try {
        qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
        var q = qrcode(0, texto.length > 1000 ? 'L' : 'M');
        q.addData(texto, 'Byte'); q.make();
        var n = q.getModuleCount(), esc = Math.max(4, Math.floor(720 / (n + 8))), m = 4 * esc;
        var c = document.createElement('canvas'); c.width = c.height = n * esc + 2 * m;
        var ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.fillStyle = '#000';
        for (var r = 0; r < n; r++) for (var k = 0; k < n; k++) if (q.isDark(r, k)) ctx.fillRect(m + k * esc, m + r * esc, esc, esc);
        var url = c.toDataURL('image/png');
        img.src = url; img.hidden = false; erro.hidden = true;
        down.href = url; down.download = 'qrcode-' + (b.dataset.nome || 'texto').replace(/[^\w\-]+/g, '_').slice(0, 40) + '.png'; down.hidden = false;
      } catch (e) {
        img.hidden = true; down.hidden = true; erro.hidden = false;
        erro.textContent = 'Este texto é demasiado longo para caber num QR code (máximo aprox. 2900 caracteres simples).';
      }
      document.getElementById('qr').showModal();
    }
  });

  // Só aceitar dígitos nos campos de PIN
  document.querySelectorAll('input.pin').forEach(function (i) {
    i.addEventListener('input', function () { i.value = i.value.replace(/\D/g, '').slice(0, 6); });
  });
})();
</script>
</body>
</html>
