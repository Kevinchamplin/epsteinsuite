<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

$pdo = db();
$driveLink = 'https://drive.google.com/drive/folders/1E8S0dLupBKCrnfRHA46DpUfluUUFeI_G?usp=sharing';
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sourceType = 'drive'; // drive-only
    $note = trim($_POST['note'] ?? '');
    $submitterEmail = trim($_POST['submitter_email'] ?? '');
    $sourceUrl = null;
    $candidate = trim($_POST['source_url'] ?? '');
    if (!$candidate) {
        $errors[] = 'Provide the Google Drive link to the file/folder.';
    } elseif (!filter_var($candidate, FILTER_VALIDATE_URL)) {
        $errors[] = 'Link must be a valid URL.';
    } else {
        $sourceUrl = $candidate;
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO ingestion_submissions (source_type, source_url, file_path, file_size, note, submitter_email, status) VALUES (:type, :url, :path, :size, :note, :email, :status)');
        $stmt->execute([
            ':type' => $sourceType,
            ':url' => $sourceUrl,
            ':path' => null,
            ':size' => null,
            ':note' => $note ?: null,
            ':email' => $submitterEmail ?: null,
            ':status' => 'pending',
        ]);
        $success = 'Received. We will queue and process this in the background.';
    }
}

include __DIR__ . '/includes/header_suite.php';
?>

<main class="min-h-screen bg-slate-50">
    <div class="max-w-4xl mx-auto px-4 py-10 space-y-6">
        <div class="space-y-2">
            <p class="text-[11px] uppercase tracking-[0.3em] text-slate-500">Submit evidence</p>
            <h1 class="text-3xl font-semibold text-slate-900">Send evidence via Google Drive</h1>
            <p class="text-slate-600 text-sm">
                Share a Google Drive link (folder or file). Everything is processed asynchronously; no local uploads or direct URLs here.
            </p>
        </div>

        <?php if ($errors): ?>
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <p class="font-medium">Please fix the following:</p>
                <ul class="list-disc list-inside space-y-1 mt-2">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <?= htmlspecialchars($success, ENT_QUOTES) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-6 bg-white border border-slate-200 shadow-sm rounded-2xl p-6">
            <input type="hidden" name="source_type" value="drive">
            <div class="space-y-2">
                <label for="source_url" class="block text-sm font-medium text-slate-800">Google Drive link (folder or file)</label>
                <input type="url" id="source_url" name="source_url" value="<?= htmlspecialchars($_POST['source_url'] ?? $driveLink, ENT_QUOTES) ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-200" placeholder="https://drive.google.com/…" />
                <p class="text-xs text-slate-500">Upload to the shared Drive folder and paste the link above.</p>
                <p class="text-xs text-blue-700">Drive folder: <a href="<?= htmlspecialchars($driveLink, ENT_QUOTES) ?>" class="underline" target="_blank" rel="noopener">Open shared folder</a></p>
            </div>

            <div class="space-y-2">
                <label for="note" class="block text-sm font-medium text-slate-800">Context / note (optional)</label>
                <textarea id="note" name="note" rows="3" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-200" placeholder="What is this data? Any specific people, dates, or cases to prioritize?"><?= htmlspecialchars($_POST['note'] ?? '', ENT_QUOTES) ?></textarea>
            </div>

            <div class="space-y-2">
                <label for="submitter_email" class="block text-sm font-medium text-slate-800">Email (optional)</label>
                <input type="email" id="submitter_email" name="submitter_email" value="<?= htmlspecialchars($_POST['submitter_email'] ?? '', ENT_QUOTES) ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-200" placeholder="you@example.com">
                <p class="text-xs text-slate-500">Used only to follow up on ingestion status.</p>
            </div>

            <div class="flex items-center justify-between">
                <p class="text-xs text-slate-500">Submissions are queued for background processing. Nothing is processed synchronously.</p>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Submit
                </button>
            </div>
        </form>
    </div>
</main>

</body>
</html>
