<?php

/*
|--------------------------------------------------------------------------
| Facturación electrónica - El Salvador (DTE, Ministerio de Hacienda)
|--------------------------------------------------------------------------
| Catálogos tomados de los "Catálogos del Sistema de Transmisión" del MH.
| IMPORTANTE: antes de pasar a producción, confirmar los códigos contra la
| versión vigente publicada en https://factura.gob.sv (pueden actualizarse).
*/

return [

    // Firmador oficial del MH (contenedor Docker svfe-api-firmador)
    'firmador_url_defecto' => 'http://localhost:8113/firmardocumento/',

    // Versión del esquema JSON por tipo de documento (se usará al generar el DTE)
    'version_json' => ['01' => 1, '03' => 3, '05' => 3, '06' => 3, '14' => 1],

    'ambientes' => [
        '00' => ['nombre' => 'Pruebas',    'url' => 'https://apitest.dtes.mh.gob.sv'],
        '01' => ['nombre' => 'Producción', 'url' => 'https://api.dtes.mh.gob.sv'],
    ],

    'proveedores' => [
        'hacienda' => 'Ministerio de Hacienda (transmisión directa)',
        'externo'  => 'Certificador / proveedor externo (API propia)',
    ],

    // CAT-002 Tipo de documento
    'tipos_dte' => [
        '01' => 'Factura',
        '03' => 'Comprobante de crédito fiscal',
        '04' => 'Nota de remisión',
        '05' => 'Nota de crédito',
        '06' => 'Nota de débito',
        '07' => 'Comprobante de retención',
        '11' => 'Factura de exportación',
        '14' => 'Factura de sujeto excluido',
        '15' => 'Comprobante de donación',
    ],

    // CAT-009 Tipo de establecimiento
    'tipos_establecimiento' => [
        '01' => 'Sucursal / Agencia',
        '02' => 'Casa matriz',
        '04' => 'Bodega',
        '07' => 'Predio y/o patio',
        '20' => 'Otro',
    ],

    // CAT-019 Actividad económica: sugerencias frecuentes para el rubro.
    // El código se puede editar a mano si la actividad registrada en Hacienda no está aquí.
    'actividades_sugeridas' => [
        ['codigo' => '10711', 'descripcion' => 'Elaboración de tortillas'],
        ['codigo' => '10712', 'descripcion' => 'Fabricación de pan, galletas y barquillos'],
        ['codigo' => '10713', 'descripcion' => 'Fabricación de repostería'],
        ['codigo' => '10790', 'descripcion' => 'Elaboración de otros productos alimenticios n.c.p.'],
        ['codigo' => '47219', 'descripcion' => 'Venta al por menor de otros productos alimenticios n.c.p.'],
        ['codigo' => '56101', 'descripcion' => 'Restaurantes'],
        ['codigo' => '56106', 'descripcion' => 'Cafeterías'],
        ['codigo' => '56109', 'descripcion' => 'Otros servicios de comida n.c.p.'],
        ['codigo' => '56210', 'descripcion' => 'Preparación de comidas para eventos (catering)'],
    ],

    // CAT-012 Departamentos y CAT-013 Municipios (reestructuración municipal 2024).
    // Los distritos son los antiguos municipios que ahora forman cada municipio.
    'departamentos' => [
        '01' => ['nombre' => 'Ahuachapán', 'municipios' => [
            '13' => ['nombre' => 'Ahuachapán Norte', 'distritos' => ['Atiquizaya', 'El Refugio', 'San Lorenzo', 'Turín']],
            '14' => ['nombre' => 'Ahuachapán Centro', 'distritos' => ['Ahuachapán', 'Apaneca', 'Concepción de Ataco', 'Tacuba']],
            '15' => ['nombre' => 'Ahuachapán Sur', 'distritos' => ['Guaymango', 'Jujutla', 'San Francisco Menéndez', 'San Pedro Puxtla']],
        ]],
        '02' => ['nombre' => 'Santa Ana', 'municipios' => [
            '14' => ['nombre' => 'Santa Ana Norte', 'distritos' => ['Masahuat', 'Metapán', 'Santa Rosa Guachipilín', 'Texistepeque']],
            '15' => ['nombre' => 'Santa Ana Centro', 'distritos' => ['Santa Ana']],
            '16' => ['nombre' => 'Santa Ana Este', 'distritos' => ['Coatepeque', 'El Congo']],
            '17' => ['nombre' => 'Santa Ana Oeste', 'distritos' => ['Candelaria de la Frontera', 'Chalchuapa', 'El Porvenir', 'San Antonio Pajonal', 'San Sebastián Salitrillo', 'Santiago de la Frontera']],
        ]],
        '03' => ['nombre' => 'Sonsonate', 'municipios' => [
            '17' => ['nombre' => 'Sonsonate Norte', 'distritos' => ['Juayúa', 'Nahuizalco', 'Salcoatitán', 'Santa Catarina Masahuat']],
            '18' => ['nombre' => 'Sonsonate Centro', 'distritos' => ['Sonsonate', 'Sonzacate', 'Nahulingo', 'San Antonio del Monte', 'Santo Domingo de Guzmán']],
            '19' => ['nombre' => 'Sonsonate Este', 'distritos' => ['Izalco', 'Armenia', 'Caluco', 'San Julián', 'Cuisnahuat', 'Santa Isabel Ishuatán']],
            '20' => ['nombre' => 'Sonsonate Oeste', 'distritos' => ['Acajutla']],
        ]],
        '04' => ['nombre' => 'Chalatenango', 'municipios' => [
            '34' => ['nombre' => 'Chalatenango Norte', 'distritos' => ['La Palma', 'Citalá', 'San Ignacio']],
            '35' => ['nombre' => 'Chalatenango Centro', 'distritos' => ['Nueva Concepción', 'Tejutla', 'La Reina', 'Agua Caliente', 'Dulce Nombre de María', 'El Paraíso', 'San Fernando', 'San Francisco Morazán', 'San Rafael', 'Santa Rita']],
            '36' => ['nombre' => 'Chalatenango Sur', 'distritos' => ['Chalatenango', 'Arcatao', 'Azacualpa', 'Comalapa', 'Concepción Quezaltepeque', 'El Carrizal', 'La Laguna', 'Las Vueltas', 'Nombre de Jesús', 'Nueva Trinidad', 'Ojos de Agua', 'Potonico', 'San Antonio de la Cruz', 'San Antonio Los Ranchos', 'San Francisco Lempa', 'San Isidro Labrador', 'San José Cancasque', 'San José Las Flores', 'San Luis del Carmen', 'San Miguel de Mercedes']],
        ]],
        '05' => ['nombre' => 'La Libertad', 'municipios' => [
            '23' => ['nombre' => 'La Libertad Norte', 'distritos' => ['Quezaltepeque', 'San Matías', 'San Pablo Tacachico']],
            '24' => ['nombre' => 'La Libertad Centro', 'distritos' => ['San Juan Opico', 'Ciudad Arce']],
            '25' => ['nombre' => 'La Libertad Oeste', 'distritos' => ['Colón', 'Jayaque', 'Sacacoyo', 'Tepecoyo', 'Talnique']],
            '26' => ['nombre' => 'La Libertad Este', 'distritos' => ['Antiguo Cuscatlán', 'Huizúcar', 'Nuevo Cuscatlán', 'San José Villanueva', 'Zaragoza']],
            '27' => ['nombre' => 'La Libertad Costa', 'distritos' => ['Chiltiupán', 'Jicalapa', 'La Libertad', 'Tamanique', 'Teotepeque']],
            '28' => ['nombre' => 'La Libertad Sur', 'distritos' => ['Comasagua', 'Santa Tecla']],
        ]],
        '06' => ['nombre' => 'San Salvador', 'municipios' => [
            '20' => ['nombre' => 'San Salvador Norte', 'distritos' => ['Aguilares', 'El Paisnal', 'Guazapa']],
            '21' => ['nombre' => 'San Salvador Oeste', 'distritos' => ['Apopa', 'Nejapa']],
            '22' => ['nombre' => 'San Salvador Este', 'distritos' => ['Ilopango', 'San Martín', 'Soyapango', 'Tonacatepeque']],
            '23' => ['nombre' => 'San Salvador Centro', 'distritos' => ['Ayutuxtepeque', 'Mejicanos', 'San Salvador', 'Cuscatancingo', 'Ciudad Delgado']],
            '24' => ['nombre' => 'San Salvador Sur', 'distritos' => ['Panchimalco', 'Rosario de Mora', 'San Marcos', 'Santo Tomás', 'Santiago Texacuangos']],
        ]],
        '07' => ['nombre' => 'Cuscatlán', 'municipios' => [
            '17' => ['nombre' => 'Cuscatlán Norte', 'distritos' => ['Suchitoto', 'San José Guayabal', 'Oratorio de Concepción', 'San Bartolomé Perulapía', 'San Pedro Perulapán']],
            '18' => ['nombre' => 'Cuscatlán Sur', 'distritos' => ['Cojutepeque', 'San Rafael Cedros', 'Candelaria', 'Monte San Juan', 'El Carmen', 'San Cristóbal', 'Santa Cruz Michapa', 'San Ramón', 'El Rosario', 'Santa Cruz Analquito', 'Tenancingo']],
        ]],
        '08' => ['nombre' => 'La Paz', 'municipios' => [
            '23' => ['nombre' => 'La Paz Oeste', 'distritos' => ['Cuyultitán', 'Olocuilta', 'San Juan Talpa', 'San Luis Talpa', 'San Pedro Masahuat', 'Tapalhuaca', 'San Francisco Chinameca']],
            '24' => ['nombre' => 'La Paz Centro', 'distritos' => ['El Rosario', 'Jerusalén', 'Mercedes La Ceiba', 'Paraíso de Osorio', 'San Antonio Masahuat', 'San Emigdio', 'San Juan Tepezontes', 'San Luis La Herradura', 'San Miguel Tepezontes', 'San Pedro Nonualco', 'Santa María Ostuma', 'Santiago Nonualco']],
            '25' => ['nombre' => 'La Paz Este', 'distritos' => ['San Juan Nonualco', 'San Rafael Obrajuelo', 'Zacatecoluca']],
        ]],
        '09' => ['nombre' => 'Cabañas', 'municipios' => [
            '10' => ['nombre' => 'Cabañas Este', 'distritos' => ['Sensuntepeque', 'Victoria', 'Dolores', 'Guacotecti', 'San Isidro']],
            '11' => ['nombre' => 'Cabañas Oeste', 'distritos' => ['Ilobasco', 'Tejutepeque', 'Jutiapa', 'Cinquera']],
        ]],
        '10' => ['nombre' => 'San Vicente', 'municipios' => [
            '14' => ['nombre' => 'San Vicente Norte', 'distritos' => ['Apastepeque', 'Santa Clara', 'San Ildefonso', 'San Esteban Catarina', 'San Sebastián', 'San Lorenzo', 'Santo Domingo']],
            '15' => ['nombre' => 'San Vicente Sur', 'distritos' => ['San Vicente', 'Guadalupe', 'Verapaz', 'Tepetitán', 'Tecoluca', 'San Cayetano Istepeque']],
        ]],
        '11' => ['nombre' => 'Usulután', 'municipios' => [
            '24' => ['nombre' => 'Usulután Norte', 'distritos' => ['Santiago de María', 'Alegría', 'Berlín', 'Mercedes Umaña', 'Jucuapa', 'El Triunfo', 'Estanzuelas', 'San Buenaventura', 'Nueva Granada']],
            '25' => ['nombre' => 'Usulután Este', 'distritos' => ['Usulután', 'Jucuarán', 'San Dionisio', 'Concepción Batres', 'Santa María', 'Ozatlán', 'Tecapán', 'Santa Elena', 'California', 'Ereguayquín']],
            '26' => ['nombre' => 'Usulután Oeste', 'distritos' => ['Jiquilisco', 'Puerto El Triunfo', 'San Agustín', 'San Francisco Javier']],
        ]],
        '12' => ['nombre' => 'San Miguel', 'municipios' => [
            '21' => ['nombre' => 'San Miguel Norte', 'distritos' => ['Ciudad Barrios', 'Sesori', 'Nuevo Edén de San Juan', 'San Gerardo', 'San Luis de la Reina', 'Carolina', 'San Antonio del Mosco', 'Chapeltique']],
            '22' => ['nombre' => 'San Miguel Centro', 'distritos' => ['San Miguel', 'Comacarán', 'Uluazapa', 'Moncagua', 'Quelepa', 'Chirilagua']],
            '23' => ['nombre' => 'San Miguel Oeste', 'distritos' => ['Chinameca', 'Nueva Guadalupe', 'Lolotique', 'San Jorge', 'San Rafael Oriente', 'El Tránsito']],
        ]],
        '13' => ['nombre' => 'Morazán', 'municipios' => [
            '27' => ['nombre' => 'Morazán Norte', 'distritos' => ['Arambala', 'Cacaopera', 'Corinto', 'El Rosario', 'Joateca', 'Jocoaitique', 'Meanguera', 'Perquín', 'San Fernando', 'San Isidro', 'Torola']],
            '28' => ['nombre' => 'Morazán Sur', 'distritos' => ['Chilanga', 'Delicias de Concepción', 'El Divisadero', 'Gualococti', 'Guatajiagua', 'Jocoro', 'Lolotiquillo', 'Osicala', 'San Carlos', 'San Francisco Gotera', 'San Simón', 'Sensembra', 'Sociedad', 'Yamabal', 'Yoloaiquín']],
        ]],
        '14' => ['nombre' => 'La Unión', 'municipios' => [
            '19' => ['nombre' => 'La Unión Norte', 'distritos' => ['Anamorós', 'Bolívar', 'Concepción de Oriente', 'El Sauce', 'Lislique', 'Nueva Esparta', 'Pasaquina', 'Polorós', 'San José La Fuente', 'Santa Rosa de Lima']],
            '20' => ['nombre' => 'La Unión Sur', 'distritos' => ['Conchagua', 'El Carmen', 'Intipucá', 'La Unión', 'Meanguera del Golfo', 'San Alejo', 'Yayantique', 'Yucuaiquín']],
        ]],
    ],
];
