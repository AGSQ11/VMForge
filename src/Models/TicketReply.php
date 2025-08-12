<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class TicketReply
{
    public static function findByTicketId(int $ticketId): array
    {
        $st = DB::pdo()->prepare('SELECT tr.*, u.email FROM ticket_replies tr JOIN users u ON tr.user_id=u.id WHERE tr.ticket_id = ? ORDER BY tr.created_at ASC');
        $st->execute([$ticketId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(int $ticketId, int $userId, string $message): int
    {
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)');
            $st->execute([$ticketId, $userId, $message]);
            $replyId = (int)$pdo->lastInsertId();

            // Update the parent ticket's updated_at timestamp
            $st = $pdo->prepare('UPDATE tickets SET updated_at = NOW(), status = "in-progress" WHERE id = ?');
            $st->execute([$ticketId]);

            $pdo->commit();
            return $replyId;
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
