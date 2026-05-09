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
            'shift'      => 'in 24h,manha,tarde,plantao_dia,noite',
            'shift_date' => 'string',
        ]);
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

        $note       = trim($this->getInput('note', ''));
        $shift      = trim($this->getInput('shift', 'plantao_dia'));
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
                 VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id"
            );
            $stmt->execute([$shift_date, $shift, $userid, $fullname, $note]);
            $row = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'message' => 'Nota salva com sucesso!',
                'id'      => $row['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Erro SQL: '.$e->getMessage()]);
        }

        $db = null;
        die();
    }
}
