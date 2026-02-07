<?php
require_once __DIR__ . '/includes/db.php';

$errors = [];
$success = false;
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');
$adminEmail = env_value('ADMIN_EMAIL') ?: 'admin@kevinchamplin.com';

/**
 * Ensure the contact_messages table exists even if the SQL migration
 * has not been run manually yet. Mirrors other auto-migration helpers.
 */
function ensureContactTable(PDO $pdo): void
{
    static $tableReady = false;
    if ($tableReady) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS contact_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            message TEXT NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (created_at),
            INDEX (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $tableReady = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($name === '') {
        $errors['name'] = 'Your name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email.';
    }
    if ($message === '') {
        $errors['message'] = 'Tell us how we can help.';
    }

    if (!$errors) {
        $pdo = db();
        ensureContactTable($pdo);
        $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, subject, message, ip_address, user_agent) VALUES (:name, :email, :subject, :message, :ip, :ua)");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':subject' => $subject !== '' ? $subject : null,
            ':message' => $message,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        $mailSubject = '[Epstein Suite] New contact message';
        $bodyLines = [
            "New inbound message submitted via the contact form:",
            '',
            "Name: {$name}",
            "Email: {$email}",
            "Subject: " . ($subject !== '' ? $subject : '(none)') ,
            '',
            $message,
            '',
            '-----',
            'Submitted at: ' . date('c'),
            'IP Address: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        ];
        $headers = [
            'From: Epstein Suite <noreply@' . ($_SERVER['SERVER_NAME'] ?? 'epsteinsuite.com') . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $email,
        ];
        @mail($adminEmail, $mailSubject, implode("\n", $bodyLines), implode("\r\n", $headers));

        $success = true;
        // reset form fields
        $name = $email = $subject = $message = '';
    }
}

$page_title = 'Contact Epstein Suite';
$meta_description = 'Reach the Epstein Suite team for tips, corrections, or partnership opportunities.';
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-1 w-full">
    <div class="max-w-4xl mx-auto px-6 py-12">
        <div class="mb-10 space-y-3">
            <p class="text-xs uppercase tracking-widest text-blue-500 font-semibold">Get in touch</p>
            <h1 class="text-4xl font-bold text-slate-900 mt-2">Contact Epstein Suite</h1>
            <p class="text-slate-700 text-lg leading-relaxed">
                We are building a factual, searchable record of the DOJ/FBI releases, flight manifests, and related public evidence. Share tips, corrections, or leads to help tighten the archive. We aim to respond within 24 hours.
            </p>
            <div class="rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-slate-800">
                <p class="font-semibold text-blue-900">What we’re trying to solve:</p>
                <ul class="mt-2 space-y-1 list-disc list-inside">
                    <li>Surface verifiable documents, logs, and citations—no speculation.</li>
                    <li>Make the public record easy to search, cite, and share.</li>
                    <li>Safeguard victims by keeping redactions intact and avoiding unverified claims.</li>
                </ul>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="mb-8 rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-emerald-800">
                <h2 class="text-lg font-semibold">Message received</h2>
                <p class="text-sm mt-2">Thanks for reaching out. Well reply soon from <strong>admin@kevinchamplin.com</strong>.</p>
            </div>
        <?php endif; ?>

        <div class="grid md:grid-cols-3 gap-8">
            <div class="md:col-span-2">
                <form method="POST" class="space-y-6 bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700" for="name">Name</label>
                        <input type="text" id="name" name="name" value="<?= htmlspecialchars($name) ?>"
                               class="mt-1 w-full rounded-xl border <?= isset($errors['name']) ? 'border-red-300' : 'border-slate-200' ?> bg-slate-50 px-4 py-2.5 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200 transition" required>
                        <?php if (isset($errors['name'])): ?><p class="mt-1 text-xs text-red-500"><?= htmlspecialchars($errors['name']) ?></p><?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700" for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>"
                               class="mt-1 w-full rounded-xl border <?= isset($errors['email']) ? 'border-red-300' : 'border-slate-200' ?> bg-slate-50 px-4 py-2.5 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200 transition" required>
                        <?php if (isset($errors['email'])): ?><p class="mt-1 text-xs text-red-500"><?= htmlspecialchars($errors['email']) ?></p><?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700" for="subject">Subject <span class="text-slate-400 font-normal">(optional)</span></label>
                        <input type="text" id="subject" name="subject" value="<?= htmlspecialchars($subject) ?>"
                               class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200 transition">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700" for="message">Message</label>
                        <textarea id="message" name="message" rows="6" class="mt-1 w-full rounded-xl border <?= isset($errors['message']) ? 'border-red-300' : 'border-slate-200' ?> bg-slate-50 px-4 py-2.5 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200 transition" required><?= htmlspecialchars($message) ?></textarea>
                        <?php if (isset($errors['message'])): ?><p class="mt-1 text-xs text-red-500"><?= htmlspecialchars($errors['message']) ?></p><?php endif; ?>
                    </div>

                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-3 text-white font-semibold shadow-lg shadow-blue-500/20 hover:opacity-95 transition">
                        Send message
                    </button>
                </form>
            </div>

            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                    <h2 class="text-lg font-semibold text-slate-900">Direct contact</h2>
                    <p class="mt-2 text-sm text-slate-600">Prefer your own email client? Reach us anytime.</p>
                    <a href="mailto:admin@kevinchamplin.com" class="mt-4 inline-flex items-center text-blue-600 font-semibold">
                        admin@kevinchamplin.com
                    </a>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                    <h2 class="text-lg font-semibold text-slate-900">Need to send files?</h2>
                    <p class="mt-2 text-sm text-slate-600">Share new evidence via Google Drive links only (multi-GB friendly). We queue and process asynchronously—no server uploads.</p>
                    <ul class="mt-3 space-y-1 text-sm text-slate-600 list-disc list-inside">
                        <li>Preferred: Google Drive folder/file link.</li>
                        <li>No local uploads here; paste the Drive or direct URL.</li>
                        <li>We review and ingest in the background.</li>
                    </ul>
                    <a href="/submit-evidence.php" class="mt-4 inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Submit evidence
                    </a>
                    <p class="mt-2 text-xs text-slate-500 break-all">
                        Drive folder: <a class="text-blue-600 underline" href="https://drive.google.com/drive/folders/1E8S0dLupBKCrnfRHA46DpUfluUUFeI_G?usp=sharing" target="_blank" rel="noopener">open shared folder</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
