# GyrosFE

Sistema de gestión de clientes para Quantica Soft.

## 📋 Descripción

GyrosFE es una aplicación web desarrollada en PHP para la gestión de clientes. Incluye sistema de autenticación seguro y dashboard interactivo.

## 🚀 Características

- ✅ Sistema de autenticación seguro con sesiones
- ✅ Dashboard responsivo con interfaz moderna
- ✅ Gestión de clientes
- ✅ Búsqueda y filtrado de datos
- ✅ Diseño limpio y profesional

## 🛠️ Tecnologías

- **Backend:** PHP 8+ (strict types)
- **Base de datos:** PostgreSQL (PDO)
- **Frontend:** HTML5, CSS3, JavaScript
- **Seguridad:** Password hashing, prepared statements, session management

## 📦 Requisitos

- PHP 8.0 o superior
- PostgreSQL
- Servidor web (Apache/Nginx)
- Extensiones PHP: PDO, pdo_pgsql

## ⚙️ Instalación

1. Clonar el repositorio:
```bash
git clone https://github.com/TU_USUARIO/gyrosfe.git
cd gyrosfe
```

2. Configurar la base de datos:
   - Crear el archivo de configuración en `/webs/quanticasoft/_private/db.php`
   - Estructura del archivo:
   ```php
   <?php
   return [
       'dsn' => 'pgsql:host=localhost;dbname=tu_database',
       'user' => 'tu_usuario',
       'pass' => 'tu_password'
   ];
   ```

3. Configurar el servidor web para apuntar al directorio del proyecto

4. Acceder a la aplicación en tu navegador

## 📁 Estructura del Proyecto

```
gyrosfe/
├── assets/          # Archivos CSS y recursos estáticos
│   └── app.css
├── lib/             # Librerías y funciones compartidas
│   ├── auth.php     # Sistema de autenticación
│   └── db_connect.php
├── ui/              # Interfaces de usuario
│   ├── login.php
│   ├── logout.php
│   └── dashboard.php
└── index.php        # Punto de entrada
```

## 🔒 Seguridad

- Uso de `declare(strict_types=1)` en todos los archivos
- Prepared statements para prevenir SQL injection
- Password hashing con `password_verify()`
- Sesiones seguras (httponly, secure, samesite)
- Escapado de HTML con `htmlspecialchars()`
- Validación de usuarios activos

## 👥 Contribuir

1. Fork el proyecto
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📝 Licencia

Este proyecto es propiedad de Quantica Soft.

## 📧 Contacto

Quantica Soft - [Tu email o sitio web]

---

Desarrollado con ❤️ por Quantica Soft
