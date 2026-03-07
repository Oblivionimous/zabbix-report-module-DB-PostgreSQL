<?php

namespace Modules\TurnosNocReport\Actions;

use CController;

class TurnosNotesSave extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'note'       => 'string',
            'shift'      => 'in 24h,manha,tarde,noite',
            'shift_date' => 'string',
        ]);
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

        $note       = trim($this->getInput('note', ''));
        $shift      = trim($this->getInput('shift', '24h'));
        $shift_date = trim($this->getInput('shift_date', date('Y-m-d')));

        if (empty($note)) {
            echo json_encode(['success' => false, 'message' => 'A nota não pode ser vazia.']);
            die();
        }

        $db = $this->getDb();
        if (!$db) {
            echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao banco de dados.']);
            die();
        }

        try {
            $userid   = \CWebUser::$data['userid'] ?? 0;
            $username = \CWebUser::$data['username'] ?? 'unknown';
            $fullname = trim((\CWebUser::$data['name'] ?? '').' '.(\CWebUser::$data['surname'] ?? '')) ?: $username;

            $stmt = $db->prepare(
                "INSERT INTO custom_shift_notes (shift_date, shift_name, analyst_userid, analyst_name, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmt->bind_param('ssiss', $shift_date, $shift, $userid, $fullname, $note);
            $stmt->execute();

            echo json_encode([
                'success' => true,
                'message' => 'Nota salva com sucesso!',
                'id'      => $db->insert_id
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Erro SQL: '.$e->getMessage()]);
        }

        $db->close();
        die();
    }
}
