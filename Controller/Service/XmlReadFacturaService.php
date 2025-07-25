<?php
namespace FacturaScripts\Plugins\xml_read\Controller\Service;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Model\Proveedor;
use FacturaScripts\Core\Model\FacturaProveedor;
use FacturaScripts\Core\Model\Pais;
use FacturaScripts\Core\Model\FormaPago;
use FacturaScripts\Core\Model\Cuenta;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use DateTime;
use Exception;

class XmlReadFacturaService
{
    protected $controller;

    public function __construct($controller)
    {
        $this->controller = $controller;
    }

    public function uploadAction() { $this->controller->uploadAction(); }
    public function processAction() { $this->controller->processAction(); }
    public function generateTableAction() { $this->controller->generateTableAction(); }
    public function getCachedDataAction() { $this->controller->getCachedDataAction(); }
    public function updateTableAction() { $this->controller->updateTableAction(); }
    public function saveFacturaProveedorAction() { $this->controller->saveFacturaProveedorAction(); }
    public function indexAction() { $this->controller->indexAction(); }
}
