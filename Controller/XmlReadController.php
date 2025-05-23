<?php
namespace FacturaScripts\Plugins\xml_read\Controller;

use FacturaScripts\Core\Base\Controller;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class XmlReadController extends Controller
{
    public $jsonData;
    public $detallesData;
    public $showTable = false;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'admin';
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

            // Convertir a UTF-8
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
            return;
        }

        $this->detallesData = $this->jsonData['detalles']['detalle'] ?? [];

        if (isset($this->detallesData['codigoPrincipal'])) {
            $this->detallesData = [$this->detallesData];
        }
    }

    protected function generateTableAction()
    {
        $this->showTable = true;
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

        // 1. Comprobante directo tipo <factura>
        if ($xml->getName() === 'factura') {
            return $this->mapFacturaDirecta($xml);
        }

        // 2. Comprobante dentro de SOAP
        if (isset($xml->Body->respuestaAutorizacionComprobante->autorizaciones->autorizacion)) {
            return $this->mapFacturaSoap($xml->Body->respuestaAutorizacionComprobante->autorizaciones->autorizacion);
        }

        // 3. Comprobante envuelto
        if (!isset($xml->comprobante)) {
            throw new \Exception('El XML no contiene la etiqueta <comprobante>.');
        }

        return $this->mapFacturaEnvueltaxml($xml);
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
}