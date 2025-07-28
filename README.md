# Plugin Lector de Facturas XML para FacturaScripts

Este proyecto es un **plugin para [FacturaScripts](https://www.facturascripts.com/)** que permite **leer facturas en formato XML**, registrar automáticamente los **productos en tu stock** y guardar la **factura completa en la base de datos**.  

El plugin está desarrollado utilizando una arquitectura **MVC (Modelo-Vista-Controlador)** para mantener un código modular y escalable.

## 📥 Instalación

1. **Descargar el plugin** desde este repositorio.
2. Acceder a tu instancia de FacturaScripts.
3. Ir a la sección **Plugins** en el panel de administración.
4. Hacer clic en **Cargar nuevo plugin**.
5. Seleccionar el archivo `.zip` descargado y cargarlo.

> ✅ Una vez cargado, el plugin estará disponible en tu instalación de FacturaScripts.

## ⚙️ Funcionalidades

- 📂 **Lector de facturas XML:** Importa facturas en formato XML.
- 📦 **Registro automático de productos:** Agrega o actualiza productos en tu inventario.
- 🧾 **Registro de facturas:** Guarda la factura completa en la base de datos de FacturaScripts.
- 🏗️ **Arquitectura MVC:** Código organizado en Modelo, Vista y Controlador para facilitar mantenimiento y escalabilidad.

## 🏗️ Arquitectura MVC

- **Modelo:** Gestiona los datos de las facturas y productos.
- **Vista:** Presenta la interfaz para cargar y visualizar facturas.
- **Controlador:** Coordina la lectura del XML y el registro de datos en el sistema.

## 📌 Requisitos

- FacturaScripts versión compatible (2024.95).
- Acceso de administrador para instalar plugins.

## 🚀 Estado del proyecto

- ✅ Desarrollo inicial con lectura XML y registro de productos/facturas.
- 🔄 Próximas mejoras planificadas (validaciones, reportes, etc.).

## 📄 Licencia

Este proyecto está licenciado bajo los términos de la **MIT License**.  
Consulta el archivo [`LICENSE`](LICENSE) para más detalles.
