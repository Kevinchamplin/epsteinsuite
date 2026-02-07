<?php
declare(strict_types=1);

function admin_render_layout(string $title, string $active, callable $content): void
{
    $links = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/admin/index.php'],
        'datasets' => ['label' => 'Datasets', 'href' => '/admin/datasets.php'],
        'ingestion' => ['label' => 'Ingestion', 'href' => '/admin/index.php#ingestion'],
        'ask' => ['label' => 'Ask Logs', 'href' => '/admin/index.php#ask'],
        'contact' => ['label' => 'Contact', 'href' => '/admin/index.php#contact'],
    ];
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title, ENT_QUOTES) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-50 min-h-screen">
        <div class="flex min-h-screen">
            <aside class="w-64 bg-slate-900 text-slate-100 flex flex-col">
                <div class="px-4 py-5 border-b border-slate-800">
                    <p class="text-[11px] uppercase tracking-[0.3em] text-slate-400">Epstein Suite</p>
                    <h1 class="text-xl font-semibold">Admin</h1>
                </div>
                <nav class="flex-1 py-4 space-y-1">
                    <?php foreach ($links as $key => $item): ?>
                        <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES) ?>"
                           class="flex items-center gap-2 px-4 py-2 text-sm <?= $active === $key ? 'bg-slate-800 text-white font-semibold' : 'text-slate-200 hover:bg-slate-800' ?>">
                            <?= htmlspecialchars($item['label'], ENT_QUOTES) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="px-4 py-4 text-[11px] text-slate-400 border-t border-slate-800">
                    Protected area
                </div>
            </aside>
            <main class="flex-1">
                <header class="bg-white border-b border-slate-200">
                    <div class="max-w-6xl mx-auto px-6 py-4 flex justify-between items-center">
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.3em] text-slate-500">Admin</p>
                            <h2 class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($title, ENT_QUOTES) ?></h2>
                        </div>
                        <div class="text-xs text-slate-600">
                            User: <?= htmlspecialchars(env_value('ADMIN_USER') ?: 'admin', ENT_QUOTES) ?>
                        </div>
                    </div>
                </header>
                <div class="max-w-6xl mx-auto px-6 py-8 space-y-8">
                    <?php $content(); ?>
                </div>
            </main>
        </div>
    </body>
    </html>
    <?php
}
