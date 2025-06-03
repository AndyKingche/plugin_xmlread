<?php
namespace FacturaScripts\Plugins\xml_read\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Lib\ExtendedController\ProductImagesTrait;
use FacturaScripts\Core\Lib\ExtendedController\DocFilesTrait;
use FacturaScripts\Core\Cache;

class XmlReadController extends Controller
{
    public $jsonData;
    public $detallesData;
    public $showTable = false;

    protected function createViews()
    {
        $this->createViewsStock();
    }


        public function getModelClassName(): string
    {
        return 'Producto';
    }

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'purchases';
        $pageData['title'] = 'Xml Read';
        $pageData['name'] = 'XmlReadController';
        $pageData['icon'] = 'fas fa-file-alt';
        $pageData['showonmenu'] = true;
        return $pageData;
    }

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

            case 'save-products':
                $this->saveProductsAction();
                break;

            default:
                $this->indexAction();
                break;
        }
    }

    protected function indexAction()
    {
        $this->jsonData = null;
        $this->detallesData = null;
        $this->showTable = false;
    }

    protected function uploadAction()
    {
        if (!$this->validateFormToken()) {
            return;
        }

        /** @var UploadedFile $uploadFile */
        $uploadFile = $this->request->files->get('facturafile');
        if (!$uploadFile instanceof UploadedFile) {
            $this->toolBox()->i18nLog()->warning('No se ha seleccionado ningún archivo.');
            return;
        }

        try {
            $xmlContent = file_get_contents($uploadFile->getPathname());

            if (!$xmlContent) {
                throw new \Exception('No se pudo leer el archivo XML.');
            }

            $xmlContent = mb_convert_encoding($xmlContent, 'UTF-8', 'auto');
            $this->toolBox()->log()->info("Contenido XML cargado: " . substr($xmlContent, 0, 500));

            $this->jsonData = $this->procesarFactura($xmlContent);
            $this->validateJsonData($this->jsonData);
            $this->processAction();
        } catch (\Exception $ex) {
            $this->toolBox()->log()->error($ex->getMessage());
            $this->toolBox()->i18nLog()->error('Error procesando el archivo XML.');
            $this->data['error'] = $ex->getMessage();
        }
    }

    protected function processAction()
    {
        if (!is_array($this->jsonData)) {
            $this->toolBox()->i18nLog()->error('No se pudo procesar el XML.');
            $this->toolBox()->log()->error('jsonData no es un array: ' . print_r($this->jsonData, true));
            return;
        }

        $this->toolBox()->log()->info("Procesando jsonData: " . json_encode($this->jsonData));
        
        if (!isset($this->jsonData['detalles'])) {
            $this->toolBox()->log()->error('No se encontró la sección detalles en jsonData');
            return;
        }

        $this->detallesData = $this->jsonData['detalles']['detalle'] ?? [];
        $this->toolBox()->log()->info("Detalles procesados: " . json_encode($this->detallesData));

        if (isset($this->detallesData['codigoPrincipal'])) {
            $this->detallesData = [$this->detallesData];
            $this->toolBox()->log()->info("Detalles convertidos a array: " . json_encode($this->detallesData));
        }

        // Guardar los detalles en la caché usando el nuevo método
        Cache::set('xml_detalles', $this->detallesData);
    }

    protected function generateTableAction()
    {
        $this->showTable = true;
        // Recuperar los detalles de la caché usando el nuevo método
        $this->detallesData = Cache::get('xml_detalles', []);
        $this->toolBox()->log()->info("Generando tabla con datos de caché: " . json_encode($this->detallesData));
    }

    protected function procesarFactura(string $xmlContent): array
    {
        libxml_clear_errors();
        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($xmlContent);
        if (!$xml) {
            foreach (libxml_get_errors() as $error) {
                $this->toolBox()->log()->error("Error XML: " . trim($error->message));
            }
            libxml_clear_errors();
            throw new \Exception('Archivo XML inválido.');
        }

        $this->toolBox()->log()->info("XML procesado correctamente. Nombre del nodo raíz: " . $xml->getName());

        if ($xml->getName() === 'factura') {
            $data = $this->mapFacturaDirecta($xml);
            $this->toolBox()->log()->info("Factura directa mapeada: " . json_encode($data));
            return $data;
        }

        if (isset($xml->Body->respuestaAutorizacionComprobante->autorizaciones->autorizacion)) {
            $data = $this->mapFacturaSoap($xml->Body->respuestaAutorizacionComprobante->autorizaciones->autorizacion);
            $this->toolBox()->log()->info("Factura SOAP mapeada: " . json_encode($data));
            return $data;
        }

        if (!isset($xml->comprobante)) {
            throw new \Exception('El XML no contiene la etiqueta <comprobante>.');
        }

        $data = $this->mapFacturaEnvueltaxml($xml);
        $this->toolBox()->log()->info("Factura envuelta mapeada: " . json_encode($data));
        return $data;
    }

    protected function mapFacturaDirecta($xml): array
    {
        $data = json_decode(json_encode($xml), true);
        $data['autorizacion'] = [
            'estado' => 'AUTORIZADO',
            'numeroAutorizacion' => 'SIN NUMERO',
            'fechaAutorizacion' => date('Y-m-d H:i:s'),
            'ambiente' => $data['infoTributaria']['ambiente'] ?? '1'
        ];
        $this->showTable = true;
        return $data;
    }

    protected function mapFacturaSoap($autorizacion): array
    {
        $xmlString = html_entity_decode((string)$autorizacion->comprobante);
        $comprobante = simplexml_load_string($xmlString);

        if (!$comprobante) {
            throw new \Exception('Error al parsear el comprobante dentro del XML SOAP.');
        }

        $data = json_decode(json_encode($comprobante), true);
        $data['autorizacion'] = [
            'estado' => (string)$autorizacion->estado,
            'numeroAutorizacion' => (string)$autorizacion->numeroAutorizacion,
            'fechaAutorizacion' => (string)$autorizacion->fechaAutorizacion,
            'ambiente' => (string)$autorizacion->ambiente,
        ];
        $this->showTable = true;
        return $data;
    }

    protected function mapFacturaEnvueltaxml($xml): array
    {
        $comprobanteXml = (string)$xml->comprobante;
        $comprobante = simplexml_load_string($comprobanteXml);

        if (!$comprobante) {
            throw new \Exception('El comprobante en el XML es inválido.');
        }

        $data = json_decode(json_encode($comprobante), true);
        $data['autorizacion'] = [
            'estado' => (string)$xml->estado,
            'numeroAutorizacion' => (string)$xml->numeroAutorizacion,
            'fechaAutorizacion' => (string)$xml->fechaAutorizacion,
            'ambiente' => (string)$xml->ambiente,
        ];
        $this->showTable = true;
        return $data;
    }

    protected function validateJsonData(array $data)
    {
        $required = [
            'infoTributaria.ruc',
            'infoTributaria.razonSocial',
            'infoTributaria.ambiente',
            'infoFactura.fechaEmision',
            'infoFactura.totalSinImpuestos',
            'autorizacion.numeroAutorizacion',
            'autorizacion.fechaAutorizacion',
        ];

        foreach ($required as $campo) {
            if (empty($this->obtenerValor($data, explode('.', $campo)))) {
                throw new \Exception("Falta el campo requerido: {$campo}");
            }
        }
    }

    protected function obtenerValor(array $array, array $claves)
    {
        foreach ($claves as $clave) {
            if (!isset($array[$clave])) {
                return null;
            }
            $array = $array[$clave];
        }
        return $array;
    }

    protected function saveProductsAction()
    {
        if (!$this->validateFormToken()) {
            return;
        }

        $this->toolBox()->log()->info("Iniciando saveProductsAction");
        
        // Recuperar los detalles de la caché usando el nuevo método
        $this->detallesData = Cache::get('xml_detalles', []);
        $this->toolBox()->log()->info("Estado actual de detallesData desde caché: " . json_encode($this->detallesData));

        if (empty($this->detallesData)) {
            $this->toolBox()->i18nLog()->error('No hay productos para guardar.');
            $this->toolBox()->log()->error('detallesData está vacío');
            return;
        }

        $savedCount = 0;
        $updatedCount = 0;

        foreach ($this->detallesData as $item) {
            $ref = $item['codigoPrincipal'] ?? '';
            $desc = $item['descripcion'] ?? '';
            $price = floatval($item['precioUnitario'] ?? 0);
            $cantidad = floatval($item['cantidad'] ?? 0);

            if (empty($ref) || empty($desc) || $price <= 0 || $cantidad <= 0) {
                continue;
            }

            // Verificar si ya existe usando DataBaseWhere correctamente
            $where = [new DataBaseWhere('referencia', $ref)];
            $productosExistentes = Producto::all($where);

            if (!empty($productosExistentes)) {
                /** @var Producto $producto */
                $producto = $productosExistentes[0];
                $producto->stockfis = floatval($producto->stockfis) + $cantidad;

                if ($producto->save()) {
                    $updatedCount++;
                    $this->toolBox()->log()->info("Producto actualizado: {$ref} (+{$cantidad} stock).");
                }
                continue;
            }

            // Nuevo producto
            $producto = new Producto();
            $producto->referencia = $ref;
            $producto->descripcion = $desc;
            $producto->pvpsiva = $price;
            $producto->stockfis = $cantidad;

            if ($producto->save()) {
                $savedCount++;
                $this->toolBox()->log()->info("Producto nuevo guardado: {$ref} ({$cantidad} stock).");
            }
        }

        $this->toolBox()->i18nLog()->info("Se guardaron {$savedCount} productos nuevos y se actualizaron {$updatedCount} productos existentes.");
    }

}