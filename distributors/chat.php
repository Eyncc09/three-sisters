<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('chat.use');

if (hasRole(ROLE_DISTRIBUTOR)) {
    $distributorId = (int) ($_SESSION['distributor_id'] ?? 0);
    if (!$distributorId) renderForbidden();
} else {
    requirePermission('distributors.manage');
    $distributorId = (int) ($_GET['distributor_id'] ?? 0);
    if (!$distributorId) {
        // No distributor chosen yet — show the conversation list instead.
        $conversations = ChatService::conversationsForOwner();
        $pageTitle = 'Messages';
        $activeNav = 'distributors';
        require __DIR__ . '/../components/header.php';
        ?>
        <div class="page-header">
            <nav class="breadcrumb"><a href="<?= BASE_URL ?>/distributors/index.php" class="text-decoration-none">Distributors</a>&nbsp;/&nbsp;Messages</nav>
            <h1 class="page-title h4 mb-0">Messages</h1>
        </div>
        <div class="ts-card">
            <?php if (!$conversations): ?>
                <div class="empty-state"><i class="bi bi-chat-dots"></i><p class="mb-0">No conversations yet. Message a distributor from their profile page.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Distributor</th><th>Last Message</th><th>Updated</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($conversations as $c): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($c['distributor_name']) ?><?php if ($c['unread_count'] > 0): ?> <span class="badge-status badge-danger"><?= (int) $c['unread_count'] ?> new</span><?php endif; ?></td>
                                <td class="small text-muted"><?= htmlspecialchars(mb_strimwidth((string) $c['last_message'], 0, 60, '…')) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars(date('M j, g:ia', strtotime($c['updated_at']))) ?></td>
                                <td class="text-end"><a href="<?= BASE_URL ?>/distributors/chat.php?distributor_id=<?= $c['distributor_id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        require __DIR__ . '/../components/footer.php';
        exit;
    }
}

$distributor = DistributorService::find($distributorId);
if (!$distributor) {
    header('Location: ' . BASE_URL . '/distributors/index.php');
    exit;
}
if (hasRole(ROLE_DISTRIBUTOR) && (int) ($_SESSION['distributor_id'] ?? 0) !== $distributorId) {
    renderForbidden();
}

$conversationId = ChatService::getOrCreateConversation($distributorId);
if (!$conversationId) {
    // No Owner account exists yet to converse with — shouldn't happen in practice.
    $flash = ['type' => 'danger', 'text' => 'No Owner account is available to start this conversation.'];
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conversationId) {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        try {
            ChatService::sendMessage($conversationId, (int) $_SESSION['user_id'], $_POST['message'] ?? '');
            header('Location: ' . BASE_URL . '/distributors/chat.php?distributor_id=' . $distributorId);
            exit;
        } catch (InvalidArgumentException $e) {
            $flash = ['type' => 'danger', 'text' => $e->getMessage()];
        }
    }
}

$messages = $conversationId ? ChatService::messages($conversationId) : [];
if ($conversationId) {
    ChatService::markRead($conversationId, (int) $_SESSION['user_id']);
}

$pageTitle = 'Chat — ' . $distributor['name'];
$activeNav = 'distributors';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/distributors/view.php?id=<?= $distributorId ?>" class="text-decoration-none"><?= htmlspecialchars($distributor['name']) ?></a>&nbsp;/&nbsp;Messages</nav>
    <h1 class="page-title h4 mb-0">Messages with <?= htmlspecialchars(hasRole(ROLE_DISTRIBUTOR) ? 'Three Sisters\' Olshoppe' : $distributor['name']) ?></h1>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> py-2"><?= htmlspecialchars($flash['text']) ?></div>
<?php endif; ?>

<div class="ts-card p-3">
    <div id="chatScroll" style="max-height: 55vh; overflow-y: auto;" class="mb-3">
        <?php if (!$messages): ?>
            <div class="empty-state py-4"><i class="bi bi-chat-dots"></i><p class="mb-0">No messages yet. Say hello — availability, restocking, order requests, and delivery schedules can all be discussed here.</p></div>
        <?php else: ?>
            <?php foreach ($messages as $m): ?>
                <?php $isMine = (int) $m['sender_id'] === (int) $_SESSION['user_id']; ?>
                <div class="d-flex flex-column mb-2 <?= $isMine ? 'align-items-end' : 'align-items-start' ?>">
                    <div class="px-3 py-2" style="max-width:75%; border-radius: 0.9rem; background: <?= $isMine ? 'var(--ts-accent-tint)' : '#F1EDEB' ?>;">
                        <div class="small fw-semibold"><?= htmlspecialchars($m['sender_name']) ?></div>
                        <div class="small"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                    </div>
                    <div class="text-muted" style="font-size:0.68rem;"><?= htmlspecialchars(date('M j, g:ia', strtotime($m['created_at']))) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($conversationId): ?>
        <form method="POST" class="d-flex gap-2">
            <?= csrfField() ?>
            <input type="text" name="message" class="form-control" placeholder="Type a message about availability, restocking, delivery schedule..." maxlength="2000" required autofocus>
            <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i></button>
        </form>
    <?php endif; ?>
</div>

<script>
const scrollBox = document.getElementById('chatScroll');
if (scrollBox) scrollBox.scrollTop = scrollBox.scrollHeight;
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>
