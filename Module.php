<?php

namespace Modules\TurnosNocReport;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {

    public function init(): void {
        // Insere "Repasse Plantão" dentro do menu Reports, após Notifications
        $menu = APP::Component()->get('menu.main');

        $reportsMenu = $menu->find(_('Reports'));

        if ($reportsMenu !== null && $reportsMenu->hasSubMenu()) {
            $reportsMenu->getSubMenu()->add(
                (new CMenuItem(_('Repasse Plantão')))
                    ->setAction('turnos.report.view')
                    ->setAliases([
                        'turnos.report.notes.save',
                        'turnos.report.notes.get',
                        'turnos.report.pdf'
                    ])
            );
        } else {
            // Fallback: adiciona no menu principal se Reports não for encontrado
            $menu->add(
                (new CMenuItem(_('Repasse Plantão')))
                    ->setAction('turnos.report.view')
            );
        }
    }
}
