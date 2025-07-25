<?php
namespace FacturaScripts\Plugins\xml_read\Controller\Service;

use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Cache;

class XmlReadProductService
{
    protected $controller;

    public function __construct($controller)
    {
        $this->controller = $controller;
    }

    public function saveProductsAction() { $this->controller->saveProductsAction(); }
    public function searchProductsAction() { $this->controller->searchProductsAction(); }
}
