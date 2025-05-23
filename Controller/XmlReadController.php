<?php
namespace FacturaScripts\Plugins\xml_read\Controller;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class XmlReadController extends Controller
{
    public $jsonData;
    public $detallesData;
    public $showTable = true;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'admin';
        $pageData['title'] = 'Xml Read';
        $pageData['name'] = 'XmlReadController';
        $pageData['icon'] = 'fas fa-page';
        $pageData['showonmenu'] = true;
        return $pageData;
    }
/*
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        
        // Acción por defecto
        $this->execAction();
    }
    
    protected function execAction()
    {
        $action = $this->request->get('action', '');
        
        switch ($action) {
            case 'upload':
                $this->uploadAction();
                break;
                
            default:
                $this->indexAction();
                break;
        }
    }
    
    protected function indexAction()
    {
        // Mostrar el formulario de subida
    }
    
    protected function uploadAction()
{
    if (false === $this->validateFormToken()) {
        return;
    }
    
    $uploadFile = $this->request->files->get('facturafile');
    if (false === $uploadFile instanceof UploadedFile) {
        $this->toolBox()->i18nLog()->warning('no-file');
        return;
    }
    
    try {
        $xmlContent = file_get_contents($uploadFile->getPathname());
        $this->toolBox()->log()->debug('Contenido XML: ' . substr($xmlContent, 0, 200) . '...');
        
        $facturaData = $this->procesarFactura($xmlContent);
        $this->toolBox()->log()->debug('Datos procesados: ' . print_r($facturaData, true));
        
        // Verificar estructura antes de usar los datos
        if (!isset($facturaData['infoTributaria']['ruc'])) {
            throw new \Exception('Estructura del XML no es válida: falta infoTributaria->ruc');
        }
        
        $this->setTemplate(false);
        $this->response->headers->set('Content-Type', 'application/json');
        $this->response->setContent(json_encode($facturaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } catch (\Exception $ex) {
        $this->toolBox()->log()->error('Error en uploadAction: ' . $ex->getMessage());
        $this->toolBox()->i18nLog()->error('error-processing-file');
        $this->response->setContent(json_encode([
            'error' => true,
            'message' => $ex->getMessage()
        ]));
    }
}
    
    protected function procesarFactura(string $xmlContent): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        
        if (false === $xml) {
            $errors = libxml_get_errors();
            $errorMessages = array_map(function($error) {
                return $error->message;
            }, $errors);
            
            throw new \Exception('Error al parsear XML: ' . implode(', ', $errorMessages));
        }
        
        if (!isset($xml->comprobante)) {
            throw new \Exception('El XML no contiene la etiqueta comprobante');
        }
        
        $comprobanteXml = (string)$xml->comprobante;
        if (empty($comprobanteXml)) {
            throw new \Exception('El comprobante está vacío');
        }
        
        $comprobante = simplexml_load_string($comprobanteXml);
        if (false === $comprobante) {
            throw new \Exception('Error al parsear el comprobante XML');
        }
        
        $jsonData = $this->xmlToArray($comprobante);
        
        // Verificar que la conversión produjo un array
        if (!is_array($jsonData)) {
            throw new \Exception('La conversión a array falló');
        }
        
        return $jsonData;
    }
    
    protected function xmlToArray(\SimpleXMLElement $xml): array 
    {
        $array = json_decode(json_encode((array)$xml), true);
        
        // Limpiar el array resultante
        $array = $this->cleanXmlArray($array);
        
        return $array;
    }

    protected function cleanXmlArray(array $array): array
    {
        foreach ($array as $key => $value) {
            // Si es un array asociativo con un solo elemento @attributes, lo simplificamos
            if (is_array($value) && count($value) === 1 && isset($value['@attributes'])) {
                $array[$key] = $value['@attributes'];
            }
            // Si es un array, limpiarlo recursivamente
            elseif (is_array($value)) {
                $array[$key] = $this->cleanXmlArray($value);
            }
        }
        
        return $array;
    }
}*/

public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->execAction();
    }
    
    protected function execAction()
    {
        $action = $this->request->get('action', '');
        
        switch ($action) {
            case 'upload':
                $this->uploadAction();
                break;
                
            case 'process':
                $this->processAction();
                break;
                
            case 'generate-table':
                $this->generateTableAction();
                break;
                
            default:
                $this->indexAction();
                break;
        }
    }
    
    protected function indexAction()
    {
        // Inicialización vacía
        $this->jsonData = null;
        $this->detallesData = null;
        $this->showTable = false;
    }
    
    protected function uploadAction()
    {
        if (false === $this->validateFormToken()) {
            return;
        }
        
        $uploadFile = $this->request->files->get('facturafile');
        if (false === $uploadFile instanceof UploadedFile) {
            $this->toolBox()->i18nLog()->warning('no-file');
            return;
        }
        
        try {
            $xmlContent = file_get_contents($uploadFile->getPathname());
            $this->jsonData = $this->procesarFactura($xmlContent);
            $this->processAction();
            
        } catch (\Exception $ex) {
            $this->toolBox()->log()->error($ex->getMessage());
            $this->toolBox()->i18nLog()->error('error-processing-file');
            $this->data['error'] = $ex->getMessage();
        }
    }
    
    protected function processAction()
    {
        // Extraer solo los detalles del JSON
        $this->detallesData = $this->jsonData['detalles']['detalle'] ?? [];
        
        // Normalizar para asegurar que siempre sea array
        if (isset($this->detallesData['codigoPrincipal'])) {
            $this->detallesData = [$this->detallesData];
        }
    }
    
    protected function generateTableAction()
    {
        $this->showTable = true;
        
    }
    /*
    protected function generateTableAction()
    {
        $this->showTable = true;
        $this->processAction(); // Asegurarnos que tenemos los datos
    }
    */
    protected function procesarFactura(string $xmlContent): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        
        if (false === $xml) {
            throw new \Exception('Invalid XML file');
        }
        
        $comprobanteXml = (string)$xml->comprobante;
        $comprobante = simplexml_load_string($comprobanteXml);
        
        if (false === $comprobante) {
            throw new \Exception('Invalid comprobante XML');
        }
        
        $jsonData = json_decode(json_encode($comprobante), true);
    
        // Agregar información de autorización
        $jsonData['autorizacion'] = [
            'estado' => (string)$xml->estado,
            'numeroAutorizacion' => (string)$xml->numeroAutorizacion,
            'fechaAutorizacion' => (string)$xml->fechaAutorizacion,
            'ambiente' => (string)$xml->ambiente
        ];

        return $jsonData;
    }

}