<?php
/**
 * TaskMaster PHP PWA
 * Gerenciador de Tarefas offline-first com Progressive Web App
 *
 * @version  2.0.0
 * @license  MIT
 */

declare(strict_types=1);

// ─── Configuração ──────────────────────────────────────────────────────────────

define('DATA_FILE', __DIR__ . '/tasks.json');
define('APP_VERSION', '2.0.0');

// ─── Funções de dados ──────────────────────────────────────────────────────────

function loadTasks(): array
{
    if (!file_exists(DATA_FILE)) {
        return [];
    }
    $raw = file_get_contents(DATA_FILE);
    return json_decode($raw ?: '[]', true) ?: [];
}

function saveTasks(array $tasks): void
{
    file_put_contents(DATA_FILE, json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function sanitize(string $value): string
{
    return trim(htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8'));
}

function generateId(): string
{
    return uniqid('task_', true);
}

// ─── Processamento de ações (POST) ────────────────────────────────────────────

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tasks  = loadTasks();

    switch ($action) {

        case 'add':
            $text     = sanitize($_POST['text'] ?? '');
            $category = sanitize($_POST['category'] ?? 'Geral');
            $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high'], true)
                ? $_POST['priority']
                : 'low';
            $dueDate  = $_POST['due_date'] ?? '';

            if ($text === '') {
                $errors[] = 'O texto da tarefa não pode estar vazio.';
            } else {
                $tasks[] = [
                    'id'         => generateId(),
                    'text'       => $text,
                    'category'   => $category,
                    'priority'   => $priority,
                    'due_date'   => $dueDate,
                    'done'       => false,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                saveTasks($tasks);
                $success = 'Tarefa adicionada com sucesso!';
            }
            break;

        case 'edit':
            $id       = $_POST['id'] ?? '';
            $text     = sanitize($_POST['text'] ?? '');
            $category = sanitize($_POST['category'] ?? 'Geral');
            $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high'], true)
                ? $_POST['priority']
                : 'low';
            $dueDate  = $_POST['due_date'] ?? '';

            if ($text === '') {
                $errors[] = 'O texto da tarefa não pode estar vazio.';
            } else {
                foreach ($tasks as &$task) {
                    if ($task['id'] === $id) {
                        $task['text']       = $text;
                        $task['category']   = $category;
                        $task['priority']   = $priority;
                        $task['due_date']   = $dueDate;
                        $task['updated_at'] = date('Y-m-d H:i:s');
                        break;
                    }
                }
                unset($task);
                saveTasks($tasks);
                $success = 'Tarefa atualizada!';
            }
            break;

        case 'toggle':
            $id = $_POST['id'] ?? '';
            foreach ($tasks as &$task) {
                if ($task['id'] === $id) {
                    $task['done'] = !$task['done'];
                    break;
                }
            }
            unset($task);
            saveTasks($tasks);
            break;

        case 'delete':
            $id    = $_POST['id'] ?? '';
            $tasks = array_filter($tasks, fn($t) => $t['id'] !== $id);
            saveTasks($tasks);
            $success = 'Tarefa removida.';
            break;

        case 'import':
            if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                $raw      = file_get_contents($_FILES['import_file']['tmp_name']);
                $imported = json_decode($raw, true);
                if (is_array($imported)) {
                    $tasks   = array_merge($tasks, $imported);
                    saveTasks($tasks);
                    $success = count($imported) . ' tarefas importadas!';
                } else {
                    $errors[] = 'Arquivo JSON inválido.';
                }
            } else {
                $errors[] = 'Erro ao fazer upload do arquivo.';
            }
            break;
    }

    // PRG – evita reenvio em F5
    if (empty($errors)) {
        $qs = $success ? '?msg=' . urlencode($success) : '';
        header('Location: index.php' . $qs);
        exit;
    }
}

// ─── Leitura e filtros ────────────────────────────────────────────────────────

$tasks      = loadTasks();
$filterCat  = $_GET['cat']    ?? '';
$filterPri  = $_GET['pri']    ?? '';
$search     = trim($_GET['q'] ?? '');
$successMsg = $_GET['msg']    ?? $success;

$categories = array_unique(array_filter(array_column($tasks, 'category')));
sort($categories);

$filtered = array_filter($tasks, function (array $task) use ($filterCat, $filterPri, $search): bool {
    if ($filterCat !== '' && $task['category'] !== $filterCat) return false;
    if ($filterPri !== '' && $task['priority']  !== $filterPri) return false;
    if ($search    !== '' && stripos($task['text'], $search) === false) return false;
    return true;
});

// Estatísticas
$total    = count($tasks);
$done     = count(array_filter($tasks, fn($t) => $t['done']));
$pending  = $total - $done;
$progress = $total > 0 ? round(($done / $total) * 100) : 0;

// Ordenar: pendentes primeiro, depois por prioridade
$priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
usort($filtered, function ($a, $b) use ($priorityOrder): int {
    if ($a['done'] !== $b['done']) return $a['done'] <=> $b['done'];
    return ($priorityOrder[$a['priority']] ?? 2) <=> ($priorityOrder[$b['priority']] ?? 2);
});

// ─── Helper de classe de prioridade ───────────────────────────────────────────

function priorityLabel(string $p): string
{
    return match ($p) {
        'high'   => 'Alta',
        'medium' => 'Média',
        default  => 'Baixa',
    };
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gerenciador de tarefas offline-first com PWA">
    <meta name="theme-color" content="#6c63ff">

    <title>TaskMaster · PHP PWA</title>

    <!-- PWA Manifest inline (sem arquivo externo) -->
    <link rel="manifest" href="manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TaskMaster">

    <!-- Fonte -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono&display=swap" rel="stylesheet">

    <style>
        /* ── Reset & Tokens ─────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:          #f4f3ff;
            --bg-card:     #ffffff;
            --bg-sidebar:  #ffffff;
            --text:        #1a1730;
            --text-muted:  #6b6888;
            --border:      #e4e2f5;
            --accent:      #6c63ff;
            --accent-dark: #4f46e5;
            --accent-glow: rgba(108, 99, 255, .15);
            --red:         #ef4444;
            --green:       #22c55e;
            --yellow:      #f59e0b;
            --radius:      14px;
            --radius-sm:   8px;
            --shadow:      0 2px 16px rgba(108,99,255,.08);
            --shadow-md:   0 8px 32px rgba(108,99,255,.12);
            --transition:  .2s ease;
        }

        [data-theme="dark"] {
            --bg:          #0f0e1a;
            --bg-card:     #1a1730;
            --bg-sidebar:  #14122a;
            --text:        #ede9ff;
            --text-muted:  #8b87aa;
            --border:      #2d2a4a;
            --accent:      #7c72ff;
            --accent-dark: #6c63ff;
            --accent-glow: rgba(124,114,255,.18);
            --shadow:      0 2px 16px rgba(0,0,0,.4);
            --shadow-md:   0 8px 32px rgba(0,0,0,.5);
        }

        /* ── Base ───────────────────────────────────────────────── */
        html { font-size: 16px; scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: grid;
            grid-template-columns: 280px 1fr;
            grid-template-rows: auto 1fr;
            transition: background var(--transition), color var(--transition);
        }

        /* ── Sidebar ─────────────────────────────────────────────── */
        .sidebar {
            grid-row: 1 / 3;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border);
            padding: 28px 20px;
            display: flex;
            flex-direction: column;
            gap: 28px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            transition: background var(--transition);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent);
            text-decoration: none;
        }

        .logo-icon {
            width: 36px; height: 36px;
            background: var(--accent);
            border-radius: var(--radius-sm);
            display: grid;
            place-items: center;
            font-size: 1.1rem;
        }

        /* Stats */
        .stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .stat-card {
            background: var(--bg);
            border-radius: var(--radius-sm);
            padding: 12px;
            text-align: center;
        }

        .stat-number {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--accent);
            line-height: 1;
        }

        .stat-label {
            font-size: .72rem;
            color: var(--text-muted);
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        /* Progress */
        .progress-wrap { display: flex; flex-direction: column; gap: 8px; }
        .progress-label { display: flex; justify-content: space-between; font-size: .8rem; color: var(--text-muted); }
        .progress-bar {
            height: 8px;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), #a78bfa);
            border-radius: 99px;
            transition: width .4s ease;
        }

        /* Filter nav */
        .nav-label {
            font-size: .7rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 0 4px;
        }

        .nav-list { list-style: none; display: flex; flex-direction: column; gap: 2px; }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--radius-sm);
            font-size: .9rem;
            color: var(--text-muted);
            text-decoration: none;
            transition: background var(--transition), color var(--transition);
        }

        .nav-link:hover, .nav-link.active {
            background: var(--accent-glow);
            color: var(--accent);
        }

        .nav-link .badge {
            margin-left: auto;
            background: var(--border);
            color: var(--text-muted);
            font-size: .7rem;
            padding: 2px 7px;
            border-radius: 99px;
            font-family: 'DM Mono', monospace;
        }

        .nav-link.active .badge {
            background: var(--accent);
            color: #fff;
        }

        /* Sidebar footer */
        .sidebar-footer { margin-top: auto; }
        .btn-theme {
            width: 100%;
            padding: 10px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text);
            cursor: pointer;
            font-size: .85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background var(--transition);
        }
        .btn-theme:hover { background: var(--accent-glow); color: var(--accent); }

        /* ── Header ──────────────────────────────────────────────── */
        .header {
            grid-column: 2;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            padding: 16px 28px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: background var(--transition);
        }

        .search-wrap { flex: 1; position: relative; }
        .search-wrap input {
            width: 100%;
            padding: 10px 16px 10px 40px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg);
            color: var(--text);
            font-size: .9rem;
            font-family: inherit;
            outline: none;
            transition: border-color var(--transition);
        }
        .search-wrap input:focus { border-color: var(--accent); }
        .search-icon {
            position: absolute;
            left: 12px; top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
        }

        /* ── Main ────────────────────────────────────────────────── */
        .main {
            grid-column: 2;
            padding: 28px;
            display: flex;
            flex-direction: column;
            gap: 24px;
            overflow-y: auto;
        }

        /* ── Add-task card ───────────────────────────────────────── */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            transition: background var(--transition);
        }

        .card-title {
            font-size: .8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 16px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr auto auto auto;
            gap: 10px;
            align-items: end;
        }

        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-label { font-size: .78rem; color: var(--text-muted); font-weight: 500; }

        .input, .select {
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg);
            color: var(--text);
            font-size: .9rem;
            font-family: inherit;
            outline: none;
            transition: border-color var(--transition);
        }
        .input:focus, .select:focus { border-color: var(--accent); }

        .btn {
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            border: none;
            font-family: inherit;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary {
            background: var(--accent);
            color: #fff;
        }
        .btn-primary:hover { background: var(--accent-dark); box-shadow: 0 4px 12px var(--accent-glow); }

        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border);
            padding: 7px 12px;
            font-size: .8rem;
        }
        .btn-ghost:hover { background: var(--accent-glow); color: var(--accent); border-color: var(--accent); }

        .btn-danger {
            background: transparent;
            color: var(--red);
            border: 1px solid transparent;
            padding: 7px 10px;
        }
        .btn-danger:hover { background: rgba(239,68,68,.1); border-color: var(--red); }

        /* ── Task list ───────────────────────────────────────────── */
        .task-list { display: flex; flex-direction: column; gap: 10px; }

        .task-item {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 18px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            box-shadow: var(--shadow);
            transition: box-shadow var(--transition), opacity var(--transition), background var(--transition);
            animation: slideIn .25s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .task-item:hover { box-shadow: var(--shadow-md); }
        .task-item.is-done { opacity: .55; }

        /* Checkbox */
        .task-check { appearance: none; width: 20px; height: 20px; min-width: 20px; border: 2px solid var(--border); border-radius: 6px; cursor: pointer; transition: all var(--transition); margin-top: 2px; }
        .task-check:checked { background: var(--accent); border-color: var(--accent); background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 10 8' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 4l3 3 5-6' stroke='white' stroke-width='1.8' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: center; }

        .task-body { flex: 1; min-width: 0; }
        .task-text { font-size: .95rem; line-height: 1.45; word-break: break-word; }
        .is-done .task-text { text-decoration: line-through; color: var(--text-muted); }

        .task-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }

        .chip {
            font-size: .72rem;
            padding: 3px 9px;
            border-radius: 99px;
            font-weight: 500;
        }

        .chip-cat  { background: var(--accent-glow); color: var(--accent); }

        .chip-high   { background: rgba(239,68,68,.12);   color: var(--red); }
        .chip-medium { background: rgba(245,158,11,.12);  color: var(--yellow); }
        .chip-low    { background: rgba(34,197,94,.12);   color: var(--green); }

        .chip-date { background: var(--bg); color: var(--text-muted); border: 1px solid var(--border); font-family: 'DM Mono', monospace; }

        .task-actions { display: flex; gap: 4px; align-items: center; }

        /* ── Edit modal ──────────────────────────────────────────── */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            backdrop-filter: blur(4px);
            display: none;
            place-items: center;
            z-index: 100;
        }
        .modal-backdrop.open { display: grid; }

        .modal {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 28px;
            width: min(480px, 90vw);
            box-shadow: var(--shadow-md);
            animation: modalIn .2s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(.95); }
            to   { opacity: 1; transform: scale(1); }
        }

        .modal-title { font-size: 1rem; font-weight: 700; margin-bottom: 20px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .modal-grid { display: grid; gap: 14px; }

        /* ── Feedback ────────────────────────────────────────────── */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: .875rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: rgba(34,197,94,.12); color: var(--green); }
        .alert-error   { background: rgba(239,68,68,.12);  color: var(--red); }

        /* ── Empty state ─────────────────────────────────────────── */
        .empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-icon { font-size: 3rem; margin-bottom: 12px; }
        .empty h3 { font-size: 1rem; font-weight: 600; margin-bottom: 4px; color: var(--text); }
        .empty p  { font-size: .875rem; }

        /* ── Import / export area ────────────────────────────────── */
        .tools-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* ── Responsive ──────────────────────────────────────────── */
        @media (max-width: 768px) {
            body { grid-template-columns: 1fr; grid-template-rows: auto auto 1fr; }

            .sidebar {
                grid-row: 1;
                height: auto;
                position: static;
                padding: 16px;
                flex-direction: row;
                flex-wrap: wrap;
                gap: 12px;
                overflow: visible;
            }

            .logo { flex: 0 0 auto; }
            .stats, .progress-wrap, .nav-section { flex: 1 1 200px; }
            .sidebar-footer { flex: 0 0 auto; margin-top: 0; }

            .header { grid-column: 1; }
            .main   { grid-column: 1; padding: 16px; }

            .form-grid { grid-template-columns: 1fr; }
        }

        /* ── Install banner ──────────────────────────────────────── */
        .install-banner {
            display: none;
            background: linear-gradient(135deg, var(--accent), #a78bfa);
            color: #fff;
            border-radius: var(--radius);
            padding: 16px 20px;
            align-items: center;
            gap: 14px;
        }
        .install-banner.visible { display: flex; }
        .install-banner p { flex: 1; font-size: .9rem; }
        .install-banner .btn { background: rgba(255,255,255,.2); color: #fff; border: 1px solid rgba(255,255,255,.3); }
        .install-banner .btn:hover { background: rgba(255,255,255,.35); }
    </style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────────────────────────────────────── -->
<aside class="sidebar">

    <a href="index.php" class="logo">
        <span class="logo-icon">✓</span>
        TaskMaster
    </a>

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?= $total ?></div>
            <div class="stat-label">Total</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $pending ?></div>
            <div class="stat-label">Pendentes</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $done ?></div>
            <div class="stat-label">Concluídas</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $progress ?>%</div>
            <div class="stat-label">Progresso</div>
        </div>
    </div>

    <!-- Progress bar -->
    <div class="progress-wrap">
        <div class="progress-label">
            <span>Progresso geral</span>
            <span><?= $done ?>/<?= $total ?></span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" style="width:<?= $progress ?>%"></div>
        </div>
    </div>

    <!-- Filtro de prioridade -->
    <div class="nav-section">
        <div class="nav-label" style="margin-bottom:8px">Prioridade</div>
        <ul class="nav-list">
            <?php
            $priorities = ['high' => 'Alta', 'medium' => 'Média', 'low' => 'Baixa'];
            foreach ($priorities as $key => $label):
                $count = count(array_filter($tasks, fn($t) => $t['priority'] === $key));
            ?>
            <li>
                <a href="?pri=<?= $key ?><?= $filterCat ? '&cat='.urlencode($filterCat) : '' ?>"
                   class="nav-link <?= $filterPri === $key ? 'active' : '' ?>">
                    <?= $label ?>
                    <span class="badge"><?= $count ?></span>
                </a>
            </li>
            <?php endforeach; ?>
            <?php if ($filterPri): ?>
            <li><a href="?<?= $filterCat ? 'cat='.urlencode($filterCat) : '' ?>" class="nav-link">Limpar filtro</a></li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- Filtro de categorias -->
    <?php if (!empty($categories)): ?>
    <div class="nav-section">
        <div class="nav-label" style="margin-bottom:8px">Categorias</div>
        <ul class="nav-list">
            <?php foreach ($categories as $cat):
                $count = count(array_filter($tasks, fn($t) => $t['category'] === $cat));
            ?>
            <li>
                <a href="?cat=<?= urlencode($cat) ?><?= $filterPri ? '&pri='.$filterPri : '' ?>"
                   class="nav-link <?= $filterCat === $cat ? 'active' : '' ?>">
                    <?= htmlspecialchars($cat) ?>
                    <span class="badge"><?= $count ?></span>
                </a>
            </li>
            <?php endforeach; ?>
            <?php if ($filterCat): ?>
            <li><a href="?<?= $filterPri ? 'pri='.$filterPri : '' ?>" class="nav-link">Limpar filtro</a></li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="sidebar-footer">
        <button class="btn-theme" onclick="toggleTheme()">
            <span id="theme-icon">🌙</span>
            <span id="theme-label">Dark Mode</span>
        </button>
    </div>

</aside>

<!-- ── Header ───────────────────────────────────────────────────────────────── -->
<header class="header">
    <form method="GET" class="search-wrap">
        <span class="search-icon">🔍</span>
        <input
            type="search"
            name="q"
            class="input"
            placeholder="Buscar tarefas..."
            value="<?= htmlspecialchars($search) ?>"
            <?= $filterCat ? '<input type="hidden" name="cat" value="'.htmlspecialchars($filterCat).'">' : '' ?>
        >
    </form>
    <?php if ($filterCat || $filterPri || $search): ?>
    <a href="index.php" class="btn btn-ghost">✕ Limpar</a>
    <?php endif; ?>
</header>

<!-- ── Main ─────────────────────────────────────────────────────────────────── -->
<main class="main">

    <!-- Install banner (PWA) -->
    <div class="install-banner" id="install-banner">
        <span>📱</span>
        <p><strong>Instalar TaskMaster</strong> · Use como app nativo no seu dispositivo!</p>
        <button class="btn" id="btn-install">Instalar</button>
        <button class="btn" onclick="document.getElementById('install-banner').classList.remove('visible')">✕</button>
    </div>

    <!-- Feedback -->
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <?php if ($successMsg): ?>
        <div class="alert alert-success" id="alert-success">✓ <?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>

    <!-- Formulário de nova tarefa -->
    <div class="card">
        <div class="card-title">Nova Tarefa</div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-grid">
                <div class="form-group" style="grid-column: 1 / -1">
                    <label class="form-label" for="task-text">Descrição</label>
                    <input id="task-text" class="input" type="text" name="text" placeholder="O que precisa ser feito?" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label" for="task-cat">Categoria</label>
                    <input id="task-cat" class="input" type="text" name="category" placeholder="Ex: Trabalho" list="cat-list" value="<?= htmlspecialchars($filterCat) ?>">
                    <datalist id="cat-list">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label" for="task-pri">Prioridade</label>
                    <select id="task-pri" class="select" name="priority">
                        <option value="low">🟢 Baixa</option>
                        <option value="medium">🟡 Média</option>
                        <option value="high">🔴 Alta</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="task-due">Prazo</label>
                    <input id="task-due" class="input" type="date" name="due_date">
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-primary" type="submit">＋ Adicionar</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Ferramentas: export / import -->
    <div class="tools-row">
        <a href="tasks.json" download="tasks.json" class="btn btn-ghost">↓ Exportar JSON</a>
        <button class="btn btn-ghost" onclick="document.getElementById('import-form').classList.toggle('hidden')">↑ Importar JSON</button>
    </div>

    <form method="POST" enctype="multipart/form-data" id="import-form" class="hidden" style="display:none">
        <input type="hidden" name="action" value="import">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="file" name="import_file" accept=".json" class="input" style="flex:1">
            <button class="btn btn-primary" type="submit">Importar</button>
        </div>
    </form>

    <!-- Lista de tarefas -->
    <section>
        <?php if (empty($filtered)): ?>
        <div class="empty">
            <div class="empty-icon">📋</div>
            <h3><?= $total > 0 ? 'Nenhuma tarefa encontrada' : 'Nada por aqui ainda' ?></h3>
            <p><?= $total > 0 ? 'Ajuste os filtros ou a busca.' : 'Adicione sua primeira tarefa acima!' ?></p>
        </div>
        <?php else: ?>
        <div class="task-list">
            <?php foreach ($filtered as $task): ?>
            <div class="task-item <?= $task['done'] ? 'is-done' : '' ?>" id="task-<?= $task['id'] ?>">

                <!-- Toggle -->
                <form method="POST">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $task['id'] ?>">
                    <button type="submit" style="background:none;border:none;padding:0;cursor:pointer">
                        <input type="checkbox" class="task-check" <?= $task['done'] ? 'checked' : '' ?> onclick="this.closest('form').submit()">
                    </button>
                </form>

                <!-- Corpo -->
                <div class="task-body">
                    <div class="task-text"><?= htmlspecialchars($task['text']) ?></div>
                    <div class="task-meta">
                        <?php if (!empty($task['category'])): ?>
                        <span class="chip chip-cat"><?= htmlspecialchars($task['category']) ?></span>
                        <?php endif; ?>
                        <span class="chip chip-<?= $task['priority'] ?>"><?= priorityLabel($task['priority']) ?></span>
                        <?php if (!empty($task['due_date'])): ?>
                        <span class="chip chip-date">📅 <?= htmlspecialchars($task['due_date']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Ações -->
                <div class="task-actions">
                    <button
                        class="btn btn-ghost"
                        onclick='openEditModal(<?= json_encode($task, JSON_HEX_APOS) ?>)'
                        title="Editar"
                    >✏️</button>
                    <form method="POST" onsubmit="return confirm('Remover esta tarefa?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $task['id'] ?>">
                        <button type="submit" class="btn btn-danger" title="Remover">🗑</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

</main>

<!-- ── Modal de edição ──────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="edit-modal" onclick="if(event.target===this)closeEditModal()">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-title" id="modal-title">✏️ Editar Tarefa</div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-id">
            <div class="modal-grid">
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <input class="input" type="text" name="text" id="edit-text" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Categoria</label>
                    <input class="input" type="text" name="category" id="edit-category" list="cat-list">
                </div>
                <div class="form-group">
                    <label class="form-label">Prioridade</label>
                    <select class="select" name="priority" id="edit-priority">
                        <option value="low">🟢 Baixa</option>
                        <option value="medium">🟡 Média</option>
                        <option value="high">🔴 Alta</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Prazo</label>
                    <input class="input" type="date" name="due_date" id="edit-due">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeEditModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Scripts ──────────────────────────────────────────────────────────────── -->
<script>
    // ── Theme ──
    const THEME_KEY = 'taskmaster-theme';

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.getElementById('theme-icon').textContent  = theme === 'dark' ? '☀️' : '🌙';
        document.getElementById('theme-label').textContent = theme === 'dark' ? 'Light Mode' : 'Dark Mode';
        localStorage.setItem(THEME_KEY, theme);
    }

    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme');
        applyTheme(current === 'dark' ? 'light' : 'dark');
    }

    // Aplica tema salvo ou preferência do sistema
    (function () {
        const saved = localStorage.getItem(THEME_KEY);
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(saved || (prefersDark ? 'dark' : 'light'));
    })();

    // ── Edit Modal ──
    function openEditModal(task) {
        document.getElementById('edit-id').value       = task.id;
        document.getElementById('edit-text').value     = task.text;
        document.getElementById('edit-category').value = task.category || '';
        document.getElementById('edit-priority').value = task.priority;
        document.getElementById('edit-due').value      = task.due_date || '';
        document.getElementById('edit-modal').classList.add('open');
        document.getElementById('edit-text').focus();
    }

    function closeEditModal() {
        document.getElementById('edit-modal').classList.remove('open');
    }

    // Fecha modal com Esc
    document.addEventListener('keydown', e => e.key === 'Escape' && closeEditModal());

    // ── Auto-dismiss alert ──
    const alert = document.getElementById('alert-success');
    if (alert) setTimeout(() => alert.remove(), 4000);

    // ── Import form toggle ──
    document.getElementById('import-form') &&
        (document.getElementById('import-form').style.display = 'none');

    document.querySelectorAll('button').forEach(btn => {
        if (btn.textContent.includes('Importar JSON')) {
            btn.onclick = () => {
                const f = document.getElementById('import-form');
                f.style.display = f.style.display === 'none' ? 'block' : 'none';
            };
        }
    });

    // ── PWA Install ──
    let deferredPrompt;
    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        deferredPrompt = e;
        document.getElementById('install-banner').classList.add('visible');
    });

    document.getElementById('btn-install')?.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        if (outcome === 'accepted') document.getElementById('install-banner').classList.remove('visible');
        deferredPrompt = null;
    });

    // ── Service Worker ──
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(console.error);
    }
</script>

</body>
</html>
