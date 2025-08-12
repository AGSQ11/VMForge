<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class Ticket
{
    public static function findById(int $ticketId): ?array
    {
        $st = DB::pdo()->prepare('SELECT t.*, u.email FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?');
        $st->execute([$ticketId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function findByUserId(int $userId): array
    {
        $st = DB::pdo()->prepare('SELECT * FROM tickets WHERE user_id = ? ORDER BY updated_at DESC');
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findAll(): array
    {
        return DB::pdo()->query('SELECT t.*, u.email FROM tickets t JOIN users u ON t.user_id = u.id ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(int $userId, string $subject, string $message, string $priority = 'medium'): int
    {
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('INSERT INTO tickets (user_id, subject, priority) VALUES (?, ?, ?)');
            $st->execute([$userId, $subject, $priority]);
            $ticketId = (int)$pdo->lastInsertId();

            TicketReply::create($ticketId, $userId, $message);

            $pdo->commit();
            return $ticketId;
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
