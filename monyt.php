<?php
/**
 * Monyt server script
 * returns linux system information to display in your app
 * @see monyt.net
 * @author Chema Garrido <chema@garridodiaz.com>
 * @license GPL v3
 */
error_reporting(1);

if (version_compare(PHP_VERSION, '5.3', '<='))
    die('You need PHP 5.3 or newer to run Monyt server script.');

//////////////// AUTHENTICATION REVIEW THIS ////////////////

// Use authentication
// If set to TRUE best choice only works with Monyt PRO
// If set to FALSE no authentication
define('USE_AUTHENTICATION', TRUE);        
define('ADMIN_USERNAME','monyt');
define('ADMIN_PASSWORD','');     //  PUT A PASSWORD THIS TO ENABLE AUTHENTICATION!!!


//////////////// DO NOT MODIFY FROM HERE ///////////////////

// authentication needed?
if ( USE_AUTHENTICATION === TRUE AND ADMIN_PASSWORD != '' AND 
   ( !isset($_SERVER['PHP_AUTH_USER']) OR !isset($_SERVER['PHP_AUTH_PW']) OR
    $_SERVER['PHP_AUTH_USER'] != ADMIN_USERNAME OR $_SERVER['PHP_AUTH_PW'] != ADMIN_PASSWORD ))
{
        header("WWW-Authenticate: Basic realm=\"Monyt Login\"");
        header("HTTP/1.0 401 Unauthorized");

        die('<html><body>
            <h1>Auth needed!</h1>
            <h2>Wrong Username or Password!</h2>
            </body></html>');
}
// end auth

//only returns the monyt version
if( isset($_GET['version'] ) )
    $return =  Monyt::VERSION;
//returns information about the server
elseif( isset($_GET['check']) )
    $return = Monyt::server_info();
//monitoring status
else
    $return = Monyt::server_status();

//debug variable
if( isset($_GET['debug']) )
{
    die(print_r($return,1));
}
else
{
    //output json information
    header('Content-type: text/json');
    header('Content-type: application/json');
    die(json_encode($return)) ;
}

/**
 * Monyt Class
 * returns linux system information to display in your app
 * @see monyt.net
 * @author Chema Garrido <chema@garridodiaz.com>
 * @license GPL v3
 */
class Monyt{

    const VERSION = '2.0.0';

    /**
     * get server status
     * @return array 
     */
    public static function server_status()
    {
        $aStats['monyt']  = self::VERSION;

        $aStats['uptime'] = trim( file_get_contents("/proc/uptime") );
        $aStats['uptime_human'] = self::uptime_human($aStats['uptime']);

        //processor usage
        $load = explode( ' ', file_get_contents("/proc/loadavg") );

        $aStats['load'] = $load[0].', '.$load[1].', '.$load[2];

        //memory info
        foreach( file('/proc/meminfo') as $line )
        {  
            $line = trim($line);
            
            if( preg_match( '/^memtotal[^\d]+(\d+)[^\d]+$/i', $line, $m ) )
            {
                $aStats['total_memory'] = $m[1];
            }
            else if( preg_match( '/^memfree[^\d]+(\d+)[^\d]+$/i', $line, $m ) )
            {
                $aStats['free_memory'] = $m[1];
            }
        }

        //new memory stats
        $aStats['memory'] = array(
                                'total'   => $aStats['total_memory'],
                                'free'    => $aStats['free_memory'],
                                'used'    => $aStats['total_memory'] - $aStats['free_memory'],
                                'percent' => round($aStats['used_memory']*100/$aStats['total_memory'],2)
                                );
        
        //hard disks info
        $aStats['hd'] = array();
        //mounts we will display always
        $mounts_allowed = array('/','/tmp','/usr','/var','/home');
        //amount of mounts to display
        $mounts = 5;

        foreach( file('/proc/mounts') as $mount )
        {
            $mount = trim($mount);
            if( $mount AND $mount[0] == '/' )
            {
                $parts = explode( ' ', $mount );
                if( $parts[0] != $parts[1] )
                {
                    $device = $parts[0];
                    $folder = $parts[1];
                    $total  = disk_total_space($folder) / 1024;
                    $free   = disk_free_space($folder) / 1024;

                    if( $total > 0  AND ($mounts > 0 OR in_array($folder,$mounts_allowed)) )
                    {
                        $used   = $total - $free;
                        $used_perc = ( $used * 100.0 ) / $total;

                        $aStats['hd'][] = array
                        (
                            'dev' => $device,
                            'total' => $total,
                            'used' => $used,
                            'free' => $free,
                            'used_perc' => $used_perc,
                            'mount' => $folder
                        );
                        $mounts--;
                    }
                }
            }
        }

        //networks info and stats usage
        $ifname = NULL;
        $aStats['net_rx'] = 0;
        $aStats['net_tx'] = 0;

        if( file_exists('/etc/network/interfaces') )
        {
            foreach( file('/etc/network/interfaces') as $line )
            {
                $line = trim($line);

                if( preg_match( '/^iface\s+([^\s]+)\s+inet\s+.+$/', $line, $m ) AND $m[1] != 'lo' )
                {
                    $ifname = $m[1];
                    break;
                }
            }
        }
        else
        {
            foreach( glob('/sys/class/net/*') as $filename )
            {
                if( $filename != '/sys/class/net/lo' AND file_exists( "$filename/statistics/rx_bytes" ) AND trim( file_get_contents("$filename/statistics/rx_bytes") ) != '0' )
                {
                    $parts = explode( '/', $filename );
                    $ifname = array_pop( $parts );
                }
            }
        }

        if( $ifname != NULL )
        {
            $aStats['net_rx'] = trim( file_get_contents("/sys/class/net/$ifname/statistics/rx_bytes") );
            $aStats['net_tx'] = trim( file_get_contents("/sys/class/net/$ifname/statistics/tx_bytes") );
        }

        return $aStats;
    }

    /**
     * server information
     * @return array 
     */
    public static function server_info()
    {
        $aCheck = array
        (
            'monyt'     => self::VERSION,
            'distro'    => '',
            'kernel'    => '',
            'cpu'       => '',
            'cores'     => '',
            'memory'    => '',
        );
        
        ///// Get distro name and verion /////
        $sDistroName = '';
        $sDistroVer  = '';

        //for ubuntu....
        if (file_exists('/etc/lsb-release'))
        {
            $distro = parse_ini_file('/etc/lsb-release');
            $aCheck['distro'] = $distro['DISTRIB_DESCRIPTION'].', '.$distro['DISTRIB_CODENAME'];
        }

        if( !$aCheck['distro'] )
        {
            foreach (glob("/etc/*_version") as $filename) 
            {
                list( $sDistroName, $dummy ) = explode( '_', basename($filename) );

                $sDistroName = ucfirst($sDistroName);
                $sDistroVer  = trim( file_get_contents($filename) );
                
                $aCheck['distro'] = "$sDistroName $sDistroVer";
                break;
            }
        }
        
        if( !$aCheck['distro'] )
        {
            if( file_exists( '/etc/issue' ) )
            {
                $lines = file('/etc/issue');
                $aCheck['distro'] = trim( $lines[0] );
            }
            else
            {
                $output = NULL;
                exec( "uname -om", $output );
                $aCheck['distro'] = trim( implode( ' ', $output ) );
            }
        }
        
        ///// Get CPU Information /////
        $cpu = file( '/proc/cpuinfo' );
        $vendor = NULL;
        $model = NULL;
        $cores = 0;
        foreach( $cpu as $line )
        {
            if( preg_match( '/^vendor_id\s*:\s*(.+)$/i', $line, $m ) )
                $vendor = $m[1];
            elseif( preg_match( '/^model\s+name\s*:\s*(.+)$/i', $line, $m ) )
                $model = $m[1];
            elseif( preg_match( '/^processor\s*:\s*\d+$/i', $line ) )
                $cores++;
        }
        
        $aCheck['cpu']    = "$vendor, $model";
        $aCheck['cores']  = $cores;
        $aCheck['kernel'] = trim(file_get_contents("/proc/version"));

        //memory info
        foreach( file('/proc/meminfo') as $line )
        {  
            $line = trim($line);
            
            if( preg_match( '/^memtotal[^\d]+(\d+)[^\d]+$/i', $line, $m ) )
            {
                $aCheck['memory'] = ROUND($m[1] / 1000 / 1000,2);
                break;
            }
            
        }



        return $aCheck;
    }

    public static function uptime_human($uptime)
    {
        $uptime = explode( ' ',$uptime);
        return self::secondsToTime(round($uptime[0]),0);
    }


    public static function secondsToTime($seconds) 
    {
        $dtF = new DateTime("@0");
        $dtT = new DateTime("@$seconds");
        return $dtF->diff($dtT)->format('%a days, %h hours, %i minutes and %s seconds');
    }
}
