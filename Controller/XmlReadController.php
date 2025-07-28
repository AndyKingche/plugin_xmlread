<?php
/*
-----------------------------------------------------------------------------------
 Plugin: XmlReadController
-----------------------------------------------------------------------------------

Este controlador forma parte de un plugin para FacturaScripts que permite importar facturas electrónicas en formato XML. El objetivo principal es automatizar el registro de compras, productos y proveedores a partir de la información contenida en los archivos XML generados por sistemas de facturación electrónica.

Funcionamiento general:

1. Subida y procesamiento de archivos XML:
   - El usuario selecciona y sube un archivo XML de factura electrónica desde la interfaz.
   - El sistema lee el archivo, lo convierte a formato UTF-8 y lo procesa para extraer la información relevante (proveedor, productos, totales, impuestos, etc.).
   - Se valida que el XML contenga todos los datos necesarios para el registro.

2. Registro automático de proveedores:
   - Si el proveedor de la factura no existe en la base de datos, el sistema lo crea automáticamente usando los datos extraídos del XML.

3. Visualización y edición de productos:
   - Los productos de la factura se muestran en una tabla editable en la vista. El usuario puede modificar cantidades, precios, descripciones y asociar productos existentes del catálogo.
   - Los cambios realizados en la vista se almacenan en un arreglo en caché (`xml_detalles`), permitiendo que los datos actualizados se utilicen al guardar la compra.

4. Guardado de productos:
   - Al guardar, el sistema recorre el arreglo de productos en caché. Si el producto existe, actualiza el stock y otros datos; si no existe, lo crea en la base de datos.

5. Registro de la factura de proveedor:
   - Se crea la factura en la base de datos, asociando los productos y el proveedor correspondiente.
   - Se calculan y guardan los totales, el IVA y el importe final de la compra.

6. Manejo de errores y validaciones:
   - El sistema incluye validaciones robustas y mensajes claros para el usuario en caso de errores de datos, problemas de conexión o inconsistencias en el XML.

Este plugin está diseñado para facilitar la gestión de compras y el control de inventario, reduciendo el trabajo manual y minimizando errores en el registro de información contable y de productos.
-----------------------------------------------------------------------------------
*/
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
use FacturaScripts\Core\Model\Proveedor;
use FacturaScripts\Core\Model\FacturaProveedor;
use FacturaScripts\Core\Model\Pais;
use FacturaScripts\Core\Model\FormaPago;
use FacturaScripts\Core\Model\Cuenta;
use FacturaScripts\Dinamic\Model\LineaFacturaProveedor as DinLineaFactura;
use DateTime;
use Exception;

class XmlReadController extends Controller
{
    // jsonData almacena la información extraída del XML, incluyendo proveedor, productos y totales.
    // detallesData contiene el arreglo de productos que se muestran y editan en la vista.
    // showTable controla la visualización de la tabla de productos en la interfaz.
    public $jsonData;
    public $detallesData;
    public $showTable = false;

    // Inicializa las vistas necesarias para el plugin.
    protected function createViews()
    {
        $this->createViewsStock();
    }


    // Devuelve el nombre del modelo principal utilizado en el controlador (Producto).
    public function getModelClassName(): string
    {
        return 'Producto';
    }

    // Configura los datos de la página para la vista principal del plugin.
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

    // Método principal que gestiona el ciclo de vida privado del controlador y ejecuta la acción solicitada.
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->execAction();
    }

    // Determina la acción a ejecutar según el parámetro recibido en la petición.
    // Permite gestionar la subida, procesamiento, guardado y edición de datos.
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
            case 'save-factura-proveedor':
                $this->saveFacturaProveedorAction();
                break;
            default:
                $this->indexAction();
                break;
        }
    }

    // Reinicia el estado del controlador y limpia la caché de productos y datos XML.
    protected function indexAction()
    {
        $this->jsonData = null;
        $this->detallesData = null;
        $this->showTable = false;
        // Limpiar la caché
        Cache::delete('xml_detalles');
        Cache::delete('xml_original_data');
    }

    // Recibe el archivo XML subido por el usuario, lo procesa y extrae los datos necesarios.
    // Si el archivo es válido, inicia el flujo de procesamiento y validación.
    protected function uploadAction()
    {
        if (!$this->validateFormToken()) {
            return;
        }
        $this->jsonData = null;
        $this->detallesData = null;
        $this->showTable = false;
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

    // Procesa los datos extraídos del XML, los valida y los almacena en caché.
    // Si el proveedor no existe, lo crea automáticamente.
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
        Cache::set('xml_detalles', $this->detallesData);
        // Guardar el JSON completo para proveedor/factura
        Cache::set('xml_original_data', $this->jsonData);
        $this->toolBox()->log()->info("Datos guardados en caché: " . json_encode($this->detallesData));
        // Guardar automáticamente el proveedor si no existe
        $infoTrib = $this->jsonData['infoTributaria'] ?? [];
        $ruc = $infoTrib['ruc'] ?? null;
        if ($ruc) {
            $proveedores = \FacturaScripts\Core\Model\Proveedor::all([
                new DataBaseWhere('cifnif', $ruc)
            ]);
            if (empty($proveedores)) {
                $this->crearProveedorDesdeInfoTrib($infoTrib);
            }
        }
    }


    // Activa la visualización de la tabla de productos y recupera los datos desde la caché.
    protected function generateTableAction()
    {
        $this->showTable = true;
        $this->detallesData = Cache::get('xml_detalles', []);
        $this->toolBox()->log()->info("Generando tabla con datos de caché: " . json_encode($this->detallesData));
    }

    // Recibe el contenido XML y lo transforma en un arreglo asociativo con los datos relevantes.
    // Soporta diferentes formatos de factura electrónica.
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

    // Valida que el arreglo de datos extraído del XML contenga todos los campos obligatorios.
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
    // Guarda la factura del proveedor, los productos y las líneas asociadas.
    // Calcula los totales y el IVA, y actualiza la base de datos.
    protected function saveFacturaProveedor(array $jsonData): void
{
    if (empty($jsonData['infoTributaria']) || empty($jsonData['infoFactura'])) {
        $this->toolBox()->i18nLog()->error('Faltan datos de infoTributaria o infoFactura.');
        return;
    }

    $infoTrib = $jsonData['infoTributaria'];
    $infoFactura = $jsonData['infoFactura'];
    $this->toolBox()->log()->info('infoTrib: ' . json_encode($infoTrib));
    $this->toolBox()->log()->info('infoFactura: ' . json_encode($infoFactura));
    $ruc = $infoTrib['ruc'] ?? null;
    $razonSocial = $infoTrib['razonSocial'] ?? null;
    $secuencial = $infoTrib['secuencial'] ?? null;

    if (!$ruc || !$razonSocial || !$secuencial) {
        $this->toolBox()->i18nLog()->error('Campos clave faltantes para guardar la factura.');
        return;
    }

    // Verificar proveedor
    $proveedores = Proveedor::all([
        new DataBaseWhere('cifnif', $ruc)
    ]);

    // Inicializar variables para evitar warnings SIEMPRE
    $codpais = null;
    $codpago = null;
    $cuentacontable = null;
    $cuentacompras = null;

    if (!empty($proveedores)) {
        $proveedor = $proveedores[0];
        // Si necesitas usar los valores aquí, asígnalos desde $proveedor:
        $codpais = $proveedor->get('codpais');
        $codpago = $proveedor->get('codpago');
        $cuentacontable = $proveedor->get('cuentacontable');
        $cuentacompras = $proveedor->get('cuentacompras');
    } else {
        // Verificar país
        $pais = Pais::all([new DataBaseWhere('codpais', 'ECU')]);
        if (!empty($pais)) {
            $codpais = 'ECU';
        } else {
            $todosPaises = Pais::all();
            if (!empty($todosPaises)) {
                $codpais = $todosPaises[0]->codpais;
            } else {
                $this->toolBox()->i18nLog()->error('No hay países disponibles en la base de datos. No se puede crear el proveedor.');
                return;
            }
        }

        // Verificar forma de pago
        $formapago = FormaPago::all([new DataBaseWhere('codpago', 'CONT')]);
        if (!empty($formapago)) {
            $codpago = 'CONT';
        } else {
            $todosPagos = FormaPago::all();
            if (!empty($todosPagos)) {
                $codpago = $todosPagos[0]->codpago;
            } else {
                $this->toolBox()->i18nLog()->error('No hay formas de pago disponibles en la base de datos. No se puede crear el proveedor.');
                return;
            }
        }

        // Verificar cuentas contables
        $cuentaContable = Cuenta::all([new DataBaseWhere('codcuenta', '22010000')]);
        if (!empty($cuentaContable)) {
            $cuentacontable = '22010000';
        } else {
            $todasCuentas = Cuenta::all();
            if (!empty($todasCuentas)) {
                $cuentacontable = $todasCuentas[0]->codcuenta;
            } else {
                $this->toolBox()->i18nLog()->error('No hay cuentas contables disponibles en la base de datos. No se puede crear el proveedor.');
                return;
            }
        }

        $cuentaCompras = Cuenta::all([new DataBaseWhere('codcuenta', '60000000')]);
        if (!empty($cuentaCompras)) {
            $cuentacompras = '60000000';
        } else {
            $todasCuentas = Cuenta::all();
            if (!empty($todasCuentas)) {
                $cuentacompras = $todasCuentas[0]->codcuenta;
            } else {
                $this->toolBox()->i18nLog()->error('No hay cuentas contables disponibles en la base de datos. No se puede crear el proveedor.');
                return;
            }
        }

        // Solo crear el proveedor si todas las variables están definidas
        if ($codpais === null || $codpago === null || $cuentacontable === null || $cuentacompras === null) {
            $this->toolBox()->i18nLog()->error('Faltan datos obligatorios para crear el proveedor.');
            return;
        }

        $proveedor = new Proveedor();
        $proveedor->codproveedor = strtoupper(substr('P' . bin2hex(random_bytes(4)), 0, 10));
        $proveedor->nombre = $razonSocial;
        $proveedor->cifnif = $ruc;
        $proveedor->tipofactura = 'F1';
        $proveedor->codpais = $codpais;
        $proveedor->codpago = $codpago;
        $proveedor->cuentacontable = $cuentacontable;
        $proveedor->cuentacompras = $cuentacompras;
        $proveedor->recargo = 0;
        $proveedor->iva = 12;

        if (!$proveedor->save()) {
            $this->toolBox()->i18nLog()->error("No se pudo crear el proveedor.");
            $this->toolBox()->log()->info("codpais usado: $codpais, codpago usado: $codpago, cuentacontable usado: $cuentacontable, cuentacompras usado: $cuentacompras");
            return;
        }
    }

    //$numeroFactura = ($infoTrib['estab'] ?? '000') . '-' . ($infoTrib['ptoEmi'] ?? '000') . '-' . $secuencial;
    $numeroFactura = $infoTrib['claveAcceso'];

    $this->toolBox()->log()->info("numeroFactura: " . $numeroFactura);
    // Verificar si ya existe la factura
    $facturaExistente = FacturaProveedor::all([
        new DataBaseWhere('observaciones', $numeroFactura.".xml"),
        new DataBaseWhere('codproveedor', $proveedor->codproveedor)
    ]);

    if (!empty($facturaExistente)) {
        $this->toolBox()->i18nLog()->info("La factura ya fue registrada: " . $numeroFactura);
        return;
    }
    $this->toolBox()->log()->info("codproveedor: " . $proveedor->cifnif);
    $this->toolBox()->log()->info("codproveedor: " . $proveedor->codproveedor);
    $this->toolBox()->log()->info("total sin impuestos: " . $infoFactura['totalSinImpuestos']);
    $this->toolBox()->log()->info("tipo de dato de totalSinImpuestos: " . gettype($infoFactura['totalSinImpuestos']));
    $fechaOriginal = $infoFactura['fechaEmision'] ?? date('Y-m-d');

    // Parseo robusto de la fecha
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fechaOriginal)) {
        $fechaObj = DateTime::createFromFormat('d/m/Y', $fechaOriginal);
    } else {
        try {
            $fechaObj = new DateTime($fechaOriginal);
        } catch (Exception $e) {
            $fechaObj = false;
        }
    }
    if ($fechaObj && $fechaObj instanceof DateTime) {
        $fechaFormateada = $fechaObj->format('Y-m-d');
    } else {
        $fechaFormateada = date('Y-m-d');
    }

    $this->toolBox()->log()->info("fecha emision: " . $fechaFormateada);

    $factura = new FacturaProveedor();
    $factura->cifnif = $ruc;
    $factura->codalmacen = 'ALG';
    $factura->coddivisa = 'USD';
    $factura->codejercicio = '2025';
    $factura->codpago = 'CONT';
    $factura->codproveedor = $proveedor->codproveedor;
    $factura->codserie = 'A';
    $factura->dtopor1 = 0;
    $factura->dtopor2 = 0;
    $factura->editable = 1;
    $factura->idempresa = 1;
    $factura->idestado = 21;
    $factura->fecha = $fechaFormateada;
    //$factura->codigo = $numeroFactura;
    $factura->nick = 'admin';
    $factura->nombre = $proveedor->nombre;
    $factura->numdocs = 0;
    $factura->observaciones = $numeroFactura.".xml";
    $factura->pagada = 1;
    

    $this->toolBox()->log()->info('Factura a guardar: ' . json_encode($factura->toArray()));
    $this->toolBox()->log()->info('Detalles: ' . json_encode($this->detallesData));

    // 1. Guardar la factura sin totales
    if ($factura->save()) {
        $this->toolBox()->i18nLog()->info("Factura del proveedor guardada: " . $factura->numero);
        $this->toolBox()->log()->info('Detalles de la factura guardada: ' . json_encode($this->detallesData));

        $sumatotales = 0; // Total sin IVA
        $totalIva = 0;     // Solo IVA
        $totalConIva = 0;  // Total con IVA (tasa con IVA)

        // 2. Guardar las líneas
        foreach ($this->detallesData as $detalle) {
            $this->toolBox()->log()->info("Guardando detalle: " . json_encode($detalle));
            $codigo = $detalle['codigoPrincipal'] ?? '';
            $productos = Producto::all([new DataBaseWhere('referencia', $codigo)]);
            if (empty($productos)) {
                $this->toolBox()->log()->warning("Producto no encontrado: " . $codigo);
                continue;
            }
            $producto = $productos[0];

            $linea = new DinLineaFactura();
            $linea->idfactura = $factura->idfactura;
            $linea->referencia = $codigo;
            $linea->descripcion = $detalle['descripcion'] ?? $producto->descripcion;
            $linea->cantidad = $detalle['cantidad'] ?? 1;
            $linea->pvpunitario = $detalle['precioUnitario'] ?? $producto->precio;
            $linea->pvptotal = ($linea->pvpunitario) * ($linea->cantidad);
            $linea->codimpuesto = 'IVA15';
            $linea->iva = 15;

            if ($linea->save()) {
                $this->toolBox()->log()->info('Línea guardada: ' . json_encode($linea->toArray()));
            } else {
                $this->toolBox()->log()->error('Error al guardar línea: ' . json_encode($linea->toArray()));
            }

            $sumatotales += $linea->pvptotal;
            $ivaLinea = $linea->pvptotal * ($linea->iva / 100);
            $totalIva += $ivaLinea;
            $totalConIva += $linea->pvptotal + $ivaLinea;
        }
        
        $this->toolBox()->log()->info('Línea guardada: ' . $sumatotales . ', IVA: ' . $totalIva . ', Total con IVA: ' . $totalConIva);
        
        $factura->neto = $sumatotales;
        $factura->netosindto = $sumatotales;
        $factura->total = $totalConIva;
        $factura->totaleuros = $totalConIva * 0.8855;
        $factura->totaliva = $totalIva;
        $factura->totalrecargo = 0;
        $factura->totalsuplidos = 0;
        $factura->totalirpf = 0;
        $factura->pagada = 1;
        $factura->vencida = 0;
        $factura->save();

        $factura->pagada = 1;
        $factura->save();

    } else {
        $this->toolBox()->i18nLog()->error("No se pudo guardar la factura del proveedor.");
    }

}

    // Acción pública para guardar la factura del proveedor usando los datos en caché.
    public function saveFacturaProveedorAction(): void
{
    // Verificamos si hay datos cargados del XML
    $jsonData = Cache::get('xml_original_data');

    if (empty($jsonData)) {
        $this->toolBox()->i18nLog()->error('No hay datos XML cargados en caché.');
        return;
    }

    try {
        $this->saveFacturaProveedor($jsonData);
    } catch (\Throwable $e) {
        $this->toolBox()->i18nLog()->error('Error al guardar proveedor/factura: ' . $e->getMessage());
    }
}


    // Guarda los productos editados en la vista, actualiza el stock y crea nuevos productos si es necesario.
    // También ejecuta el guardado de la factura proveedor.
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
                    $producto->precio = $price;
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
            $producto->precio = $price;
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

        // Ejecutar también el guardado de la factura proveedor
        $this->saveFacturaProveedorAction();

        // Si la petición es AJAX, devolver JSON y no recargar la página
        if ($this->request->isXmlHttpRequest() || $this->request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // Actualiza el arreglo en caché con los productos modificados desde la vista.
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
    
    // Permite buscar productos existentes en la base de datos para asociarlos a los detalles de la factura.
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

    // Devuelve los datos de productos almacenados en caché para su uso en la vista.
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

    // Crea un proveedor nuevo en la base de datos usando los datos extraídos del XML si no existe previamente.
    private function crearProveedorDesdeInfoTrib(array $infoTrib): void
    {
        $ruc = $infoTrib['ruc'] ?? null;
        $razonSocial = $infoTrib['razonSocial'] ?? 'Proveedor XML';
        if (!$ruc || !$razonSocial) {
            $this->toolBox()->i18nLog()->error('Faltan datos clave para crear el proveedor.');
            return;
        }

        // Verificar país
        $pais = Pais::all([new DataBaseWhere('codpais', 'ECU')]);
        if (!empty($pais)) {
            $codpais = 'ECU';
        } else {
            $todosPaises = Pais::all();
            if (!empty($todosPaises)) {
                $codpais = $todosPaises[0]->codpais;
            } else {
                $this->toolBox()->i18nLog()->error('No hay países disponibles en la base de datos. No se puede crear el proveedor.');
                return;
            }
        }

        // Verificar forma de pago
        $formapago = FormaPago::all([new DataBaseWhere('codpago', 'CONT')]);
        if (!empty($formapago)) {
            $codpago = 'CONT';
        } else {
            $todosPagos = FormaPago::all();
            if (!empty($todosPagos)) {
                $codpago = $todosPagos[0]->codpago;
            } else {
                $this->toolBox()->i18nLog()->error('No hay formas de pago disponibles en la base de datos. No se puede crear el proveedor.');
                return;
            }
        }

        // Verificar cuentas contables
        $cuentaContable = Cuenta::all([new DataBaseWhere('codcuenta', '22010000')]);
        if (!empty($cuentaContable)) {
            $cuentacontable = '22010000';
        } else {
            $todasCuentas = Cuenta::all();
            if (!empty($todasCuentas)) {
                $cuentacontable = $todasCuentas[0]->codcuenta;
            } else {
                $this->toolBox()->i18nLog()->error('No hay cuentas contables disponibles en la base de datos. No se puede crear el proveedor.');
                return;
            }
        }

        $cuentaCompras = Cuenta::all([new DataBaseWhere('codcuenta', '60000000')]);
        if (!empty($cuentaCompras)) {
            $cuentacompras = '60000000';
        } else {
            $todasCuentas = Cuenta::all();
            if (!empty($todasCuentas)) {
                $cuentacompras = $todasCuentas[0]->codcuenta;
            } else {
                $this->toolBox()->i18nLog()->error('No hay cuentas contables disponibles en la base de datos. No se puede crear el proveedor.');
                return;
            }
        }

        $proveedor = new Proveedor();
        $proveedor->codproveedor = strtoupper(substr('P' . bin2hex(random_bytes(4)), 0, 10));
        $proveedor->nombre = $razonSocial;
        $proveedor->cifnif = $ruc;
        $proveedor->tipofactura = 'F1';
        $proveedor->codpais = $codpais;
        $proveedor->codpago = $codpago;
        $proveedor->cuentacontable = $cuentacontable;
        $proveedor->cuentacompras = $cuentacompras;
        $proveedor->recargo = 0;
        $proveedor->iva = 15;

        if ($proveedor->save()) {
            $this->toolBox()->i18nLog()->info("Proveedor creado automáticamente: $razonSocial ($ruc)");
        } else {
            $this->toolBox()->i18nLog()->error("No se pudo crear el proveedor automáticamente.");
        }
    }

}