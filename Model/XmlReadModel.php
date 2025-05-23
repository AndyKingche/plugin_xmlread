<?php
/**
 * Modelo para almacenar facturas electrónicas procesadas
 */
namespace FacturaScripts\Plugins\xml_read\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

class XmlReadModel extends ModelClass
{
    use ModelTrait;
    
    public $idfactura;
    public $fecha;
    public $numeroautorizacion;
    public $rucemisor;
    public $razonsocialemisor;
    public $identificacioncomprador;
    public $razonsocialcomprador;
    public $importetotal;
    public $estado;
    public $jsondata;
    
    public static function primaryColumn()
    {
        return 'idfactura';
    }
    
    public static function tableName()
    {
        return 'facturaselectronicas';
    }
    
    public function install()
    {
        // Creamos la tabla si no existe
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->tableName() . " (
            idfactura INT AUTO_INCREMENT PRIMARY KEY,
            fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            numeroautorizacion VARCHAR(100) NOT NULL,
            rucemisor VARCHAR(13) NOT NULL,
            razonsocialemisor VARCHAR(100) NOT NULL,
            identificacioncomprador VARCHAR(13) NOT NULL,
            razonsocialcomprador VARCHAR(100) NOT NULL,
            importetotal DECIMAL(12,2) NOT NULL,
            estado VARCHAR(20) NOT NULL,
            jsondata LONGTEXT NOT NULL
        );";
        
        return $this->dataBase->exec($sql);
    }
}

