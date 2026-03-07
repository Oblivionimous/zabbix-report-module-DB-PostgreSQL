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

    private function getDb(): ?\mysqli {
        try {
            $server = $GLOBALS['DB']['SERVER']   ?? 'localhost';
            $port   = $GLOBALS['DB']['PORT']     ?? '3306';
            $dbname = $GLOBALS['DB']['DATABASE'] ?? 'zabbix';
            $user   = $GLOBALS['DB']['USER']     ?? 'zabbix';
            $pass   = $GLOBALS['DB']['PASSWORD'] ?? '';

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $mysqli = new \mysqli($server, $user, $pass, $dbname, (int)$port);
            $mysqli->set_charset('utf8mb4');
            return $mysqli;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $shift      = $_GET['shift']      ?? $_POST['shift']      ?? '24h';
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
            $stmt->bind_param('ss', $shift_date, $shift);
            $stmt->execute();
            $result = $stmt->get_result();

            $notes = [];
            while ($row = $result->fetch_assoc()) {
                $notes[] = $row;
            }

            echo json_encode(['success' => true, 'notes' => $notes]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'notes' => []]);
        }

        $db->close();
        die();
    }
}
