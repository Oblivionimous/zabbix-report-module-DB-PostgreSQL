<?php

namespace Modules\TurnosNocReport\Actions;

use CController;

class TurnosNotesGet extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return true;
    }

    private function getDb(): ?\PDO {
        try {
            $host   = $GLOBALS['DB']['SERVER']   ?? 'localhost';
            $port   = $GLOBALS['DB']['PORT']     ?? '5432';
            $dbname = $GLOBALS['DB']['DATABASE'] ?? 'zabbix';
            $user   = $GLOBALS['DB']['USER']     ?? 'zabbix';
            $pass   = $GLOBALS['DB']['PASSWORD'] ?? '';

            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
            return new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $shift      = $_GET['shift']      ?? $_POST['shift']      ?? 'plantao_dia';
        $shift_date = $_GET['shift_date'] ?? $_POST['shift_date'] ?? date('Y-m-d');

        $db = $this->getDb();
        if (!$db) {
            echo json_encode(['success' => false, 'message' => 'Erro DB.', 'notes' => []]);
            die();
        }

        try {
            $stmt = $db->prepare(
                "SELECT id, analyst_name, notes, created_at
                 FROM custom_shift_notes
                 WHERE shift_date = ? AND shift_name = ?
                 ORDER BY created_at DESC"
            );
            $stmt->execute([$shift_date, $shift]);
            $notes = $stmt->fetchAll();

            echo json_encode(['success' => true, 'notes' => $notes]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'notes' => []]);
        }

        $db = null;
        die();
    }
}
