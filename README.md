# php-milight
PHP Class to control MiLights through the Wifi Controller Box, iBox1 and iBox2 protocol version 6.


If you have a working iBox controller with linked lights, this is a basic usage example to set the lamps in zone 1 to warm white, with 80% brightness:
```php
$oMiLight = new milight();
$oMiLight->setController("192.168.1.50"); // the IP-address of you iBox Controller Box
$oMiLight->changeLight(["color" => 256, "intensity" => 80], 1);
```

To link a light to zone 1, call this function within 3 seconds after switching on the light:
```php
$oMiLight->linkBulb(1);
```

To unlink a light from all linked controllers in zone 1:
```php
$oMiLight->unlinkBulb(1);
```

All available options for the changeLight function:

**color:**

0 = red

85 = green

170 = blue

255 = red

256 = 100% warm white

356 = 100% cold white


**intensity:**

from 0 (very dim)

to 100 (full brightness)


**saturation (applies only to colors):**

0 = mix no white

100 = mix 100% white


**special:**

on = turn lights on

off = turn lights off

night = turn on night mode

speedup = speed up (for disco modes)

speeddown = speed down (for disco modes)


**disco:**

1 to 9


To change the internal lamp of the iBox1 controller (U shaped device), use this function:
```php
$oMiLight->changeIBoxLight("color", 1); // sets the color to red
```

The first parameter can be one of these:

on

off

white

speedup

speeddown

color (requires second parameter from 0 to 255)

intensity (requires second parameter from 0 to 100)

disco (requires second parameter from 1 to 9)


NOTE: The iBox1 lamps remembers its intensity setting for white and color seperately.
