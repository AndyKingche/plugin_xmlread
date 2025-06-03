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

            case 'search-products':
                $this->searchProductsAction();
                break;

            case 'get-cached-data':
                $this->getCachedDataAction();
                break;

            case 'update-table':
                $this->updateTableAction();
                break;

            default:
                $this->indexAction();
                break;
        }
    }

    protected function indexAction()
    {
        // Reiniciar todas las variables
        $this->jsonData = null;
        $this->detallesData = null;
        $this->showTable = false;
        
        // Limpiar la caché
        Cache::delete('xml_detalles');
        Cache::delete('xml_original_data');
    }

    protected function uploadAction()
    {
        if (!$this->validateFormToken()) {
            return;
        }

        // Reiniciar todas las variables
        $this->jsonData = null;
        $this->detallesData = null;
        $this->showTable = false;
        
        // Limpiar la caché
        Cache::delete('xml_detalles');
        Cache::delete('xml_original_data');

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

        // Guardar los datos originales en la caché
        Cache::set('xml_detalles', $this->detallesData);
        Cache::set('xml_original_data', $this->detallesData);
        $this->toolBox()->log()->info("Datos guardados en caché: " . json_encode($this->detallesData));
    }

    protected function generateTableAction()
    {
        $this->showTable = true;
        // Recuperar los detalles de la caché
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
        
        // Obtener datos modificados del formulario
        $modifiedDataJson = $this->request->request->get('modifiedData', '[]');
        $this->toolBox()->log()->info("Datos modificados recibidos (raw): " . $modifiedDataJson);
        
        $modifiedData = json_decode($modifiedDataJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->toolBox()->log()->error("Error decodificando JSON: " . json_last_error_msg());
            return;
        }

        $this->toolBox()->log()->info("Datos modificados decodificados: " . json_encode($modifiedData));

        // Si no hay datos modificados, intentar usar los datos de la caché
        if (empty($modifiedData)) {
            $this->detallesData = Cache::get('xml_detalles', []);
            $this->toolBox()->log()->info("Usando datos de caché: " . json_encode($this->detallesData));
            
            if (empty($this->detallesData)) {
                $this->toolBox()->i18nLog()->error('No hay productos para guardar.');
                $this->toolBox()->log()->error('No hay datos para procesar');
                return;
            }
        } else {
            $this->detallesData = $modifiedData;
            // Actualizar la caché con los datos modificados
            Cache::set('xml_detalles', $modifiedData);
            $this->toolBox()->log()->info("Datos modificados guardados en caché");
        }

        $savedCount = 0;
        $updatedCount = 0;

        foreach ($this->detallesData as $item) {
            $this->toolBox()->log()->info("Procesando item: " . json_encode($item));
            
            // Determinar qué código usar
            $fromSelect = $item['fromSelect'] ?? false;
            $ref = $fromSelect ? ($item['codigoPrincipal'] ?? '') : ($item['codigoOriginal'] ?? $item['codigoPrincipal'] ?? '');
            $desc = $item['descripcion'] ?? '';
            $price = floatval($item['precioUnitario'] ?? 0);
            $cantidad = floatval($item['cantidad'] ?? 0);

            $this->toolBox()->log()->info("Datos extraídos - ref: {$ref}, desc: {$desc}, price: {$price}, cantidad: {$cantidad}, fromSelect: " . ($fromSelect ? 'true' : 'false'));

            if (empty($ref) || empty($desc) || $price <= 0 || $cantidad <= 0) {
                $this->toolBox()->log()->warning("Item ignorado por datos inválidos: " . json_encode($item));
                continue;
            }

            // Verificar si ya existe
            $where = [new DataBaseWhere('referencia', $ref)];
            $productosExistentes = Producto::all($where);

            if (!empty($productosExistentes)) {
                /** @var Producto $producto */
                $producto = $productosExistentes[0];
                
                // Si viene del select, actualizar todo
                if ($fromSelect) {
                    $producto->descripcion = $desc;
                    $producto->pvpsiva = $price;
                    $producto->pvp = $price * (1 + ($producto->iva / 100));
                    $producto->stockfis = floatval($producto->stockfis) + $cantidad;
                    
                    if ($producto->save()) {
                        $updatedCount++;
                        $this->toolBox()->log()->info("Producto actualizado desde select: {$ref}");
                    }
                } else {
                    // Si no viene del select, solo actualizar el stock
                    $producto->stockfis = floatval($producto->stockfis) + $cantidad;
                    
                    if ($producto->save()) {
                        $updatedCount++;
                        $this->toolBox()->log()->info("Producto actualizado (solo stock): {$ref}");
                    }
                }
                continue;
            }

            // Nuevo producto
            $producto = new Producto();
            $producto->referencia = $ref;
            $producto->descripcion = $desc;
            $producto->stockfis = $cantidad;
            $producto->pvpsiva = $price;
            $producto->pvp = $price * 1.12;
            $producto->coste = $price;
            $producto->preciocoste = $price;
            $producto->margen = 0;
            $producto->margenm = 0;
            $producto->iva = 12;

            if ($producto->save()) {
                $savedCount++;
                $this->toolBox()->log()->info("Producto nuevo guardado: {$ref}");
            }
        }

        $this->toolBox()->i18nLog()->info("Se guardaron {$savedCount} productos nuevos y se actualizaron {$updatedCount} productos existentes.");
    }

    protected function updateTableAction()
    {
        if (!$this->validateFormToken()) {
            return;
        }

        $modifiedDataJson = $this->request->request->get('modifiedData', '[]');
        $this->toolBox()->log()->info("Datos recibidos para actualizar caché: " . $modifiedDataJson);
        
        $modifiedData = json_decode($modifiedDataJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->toolBox()->log()->error("Error decodificando JSON: " . json_last_error_msg());
            return;
        }

        if (!empty($modifiedData)) {
            // Actualizar los datos en memoria
            $this->detallesData = $modifiedData;
            
            // Actualizar la caché con los datos modificados
            Cache::set('xml_detalles', $modifiedData);
            $this->toolBox()->log()->info("Caché actualizada con nuevos datos: " . json_encode($modifiedData));
        }

        // Limpiar cualquier salida anterior
        ob_clean();
        
        // Establecer los headers correctos
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        
        // Enviar respuesta de éxito
        echo json_encode(['success' => true]);
        exit;
    }

    protected function searchProductsAction()
    {
        try {
            $term = $this->request->get('term', '');
            $page = $this->request->get('page', 1);
            $limit = 10;
            $offset = ($page - 1) * $limit;

            $this->toolBox()->log()->info("Buscando productos con término: " . $term);

            $where = [];
            if (!empty($term)) {
                // Buscar en referencia o descripción usando OR
                $where[] = new DataBaseWhere('referencia', $term, 'LIKE');
                $where[] = new DataBaseWhere('descripcion', $term, 'LIKE', 'OR');
            }

            $producto = new Producto();
            $productos = $producto->all($where, ['referencia' => 'ASC'], $offset, $limit);
            
            $this->toolBox()->log()->info("Productos encontrados: " . count($productos));
            
            $results = [];
            foreach ($productos as $item) {
                $results[] = [
                    'id' => $item->referencia,
                    'referencia' => $item->referencia,
                    'descripcion' => $item->descripcion,
                    'pvpsiva' => $item->pvpsiva,
                    'text' => $item->referencia . ' - ' . $item->descripcion
                ];
            }

            $response = [
                'items' => $results,
                'more' => count($results) === $limit
            ];

            $this->toolBox()->log()->info("Enviando respuesta: " . json_encode($response));

            // Limpiar cualquier salida anterior
            ob_clean();
            
            // Establecer los headers correctos
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, must-revalidate');
            
            // Enviar la respuesta
            echo json_encode($response);
            exit;
        } catch (\Exception $e) {
            $this->toolBox()->log()->error("Error en searchProductsAction: " . $e->getMessage());
            
            // Limpiar cualquier salida anterior
            ob_clean();
            
            // Establecer los headers correctos
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, must-revalidate');
            
            // Enviar la respuesta de error
            echo json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);
            exit;
        }
    }

    protected function getCachedDataAction()
    {
        if (!$this->validateFormToken()) {
            return;
        }

        $cachedData = Cache::get('xml_detalles', []);
        $this->toolBox()->log()->info("Enviando datos en caché: " . json_encode($cachedData));

        // Limpiar cualquier salida anterior
        ob_clean();
        
        // Establecer los headers correctos
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        
        // Enviar la respuesta
        echo json_encode($cachedData);
        exit;
    }

}