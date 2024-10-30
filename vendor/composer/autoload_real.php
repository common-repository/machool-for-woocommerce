<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInitf10a2129dc4c8ff99907b7d152561918
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInitf10a2129dc4c8ff99907b7d152561918', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInitf10a2129dc4c8ff99907b7d152561918', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInitf10a2129dc4c8ff99907b7d152561918::getInitializer($loader));

        $loader->register(true);

        return $loader;
    }
}
