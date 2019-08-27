<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/10/1
 * Baidu searcher
 */

namespace QL\Ext\pc;

use QL\Contracts\PluginContract;
use QL\QueryList;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;//多线程
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;

class Baidu implements PluginContract
{
    protected $ql;
    protected $keyword;
    protected $pageNumber = 10;//必须除以一半 不然太卡拉！
    protected $httpOpt = [];
    protected $url = [];
    const API = 'https://www.baidu.com/s';
    const RULES = [
      'title' => ['h3','text'],
      'link' => ['h3>a','href'],
      'content' => ['.c-abstract','text']
    ];
    const RANGE = '.result';

    public function __construct(QueryList $ql, $pageNumber)
    {
        $this->ql = $ql->rules(self::RULES)->range(self::RANGE);
        $this->pageNumber = $pageNumber;
    }

    public static function install(QueryList $queryList, ...$opt)
    {
        $name = $opt[0] ?? 'baidu';
        $queryList->bind($name,function ($pageNumber = 10){
            return new Baidu($this,$pageNumber);
        });
    }

    public function setHttpOpt(array $httpOpt = [])
    {
        $this->httpOpt = $httpOpt;
        return $this;
    }

    public function search($keyword)
    {
        $this->keyword = $keyword;
        return $this;
    }

    public function page($page = 1,$realURL = false)
    {

/*
        $data =  $this->query($page)->query()->getData(function ($item) use($realURL){
            $realURL && $item['link'] = $this->getRealURL($item['link'],false);
            return $item;
        });
*/
// 捕获错误
    try {
        $data = $this->query($page)->query()->getData()->all();
    } catch (RequestException $e) {
        return array();
    }
        $realURL && $data = $this->getRealURL_V2($data);


        return $this->setArray($data);
    }
    //针对数组处理
    public function setArray($data)
    {
        $new = array();
        for ($i=0; $i < count($data); $i++) { 
            if($data[$i]['link'] !== ''){
                $new[] = array(
                    'title' => $data[$i]['title'],
                    'link' => $data[$i]['link'],
                    'content' => $data[$i]['content'],
                    );
            }
        }
        return $new;
    }
    public function getCount()
    {
        $count = 0;
        $text =  $this->query(1)->find('.nums')->text();
        if(preg_match('/[\d,]+/',$text,$arr))
        {
            $count = str_replace(',','',$arr[0]);
        }
        return (int)$count;
    }

    public function getCountPage()
    {
        $count = $this->getCount();
        $countPage = ceil($count / $this->pageNumber);
        return $countPage;
    }

    protected function query($page = 1)
    {
        $this->ql->get(self::API,[
            'wd' => $this->keyword,
            'rn' => $this->pageNumber,
            'pn' => $this->pageNumber * ($page-1)
        ],$this->httpOpt);
        return $this->ql;
    }

    protected  function getRealURL_V2($array=array())
    {

if (count($array) == 0) {
    return $array;
}
   $client = new Client([
      'verify'=>false,//不验证HTTPS
      'http_errors'=>false,//不会弹出报错信息
      'timeout' => 3,//超时的秒
      'allow_redirects' => false,//不重定向
      'headers' => $this->httpOpt,//请求头部
      ]);
   $url = array();

for ($i=0; $i < count($array); $i++) { 
    $url['s'][] = $client->getAsync($array[$i]['link']);
    $url['t'][] = $i;
}
if (count($url) == 0) {
    return $array;
}

$response = Promise\unwrap($url['s']);
for ($i=0; $i < count($url['t']); $i++) { 
    $code = $response[$i]->getStatusCode();

    if($code == 200 || $code == 301 || $code == 302){
        // 获取响应头部信息
        $header = $response[$i]->getHeaders();
        if ($code == 301 || $code == 302)
        {
            if(is_array($header['Location']))
            {
                $array[$url['t'][$i]]['link'] = $header['Location'][0];
            }
            else
            {
                $array[$url['t'][$i]]['link'] = $header['Location'];
            }
        }
        else{
            $array[$url['t'][$i]]['link'] = '';
        }
    }else{
        $array[$url['t'][$i]]['link'] = '';
    }
}
    return $array;

    }
    protected  function getRealURL($url,$state = false)
    {
        //得到百度跳转的真正地址
        //print_r($url);
if(!$state){
    // 新版本请求 超级快的！ 0.1~0.04左右

    $client = new Client([
      'verify'=>false,//不验证HTTPS
      'http_errors'=>false,//不会弹出报错信息
      'timeout' => 5,//超时的秒
      'allow_redirects' => false,//不重定向
      ]);
    $data_url = array();

    //异常捕获
    try {
        $response = $client->get($url);
    } catch (RequestException $e) {
        //请求错误，返回空白URL 就会剔除
        return '';
    }

    $code = $response->getStatusCode();
    if($code !== 200 && $code !== 301 && $code !== 302){
       return $url;
    }
    // 获取响应头部信息
    $header = $response->getHeaders();


    if ($code == 301 || $code == 302)
    {
        if(is_array($header['Location']))
        {
            return $header['Location'][0];
        }
        else
        {
            return $header['Location'];
        }
    }
    else
    {
        return $url;
    }

    return;

}else{
    /* 版本  耗时 0.1S 太慢了*/
    $ch = curl_init();
    //设置访问的url地址   
    curl_setopt($ch, CURLOPT_URL, $url);
    // 不需要页面内容
    curl_setopt($ch, CURLOPT_NOBODY, true);
    // 不直接输出
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // 返回最后的Location
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // 不验证SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    // 只是GET
    curl_setopt($ch, CURLOPT_POST, false);
    // 启用时会将头文件的信息作为数据流输出
    curl_setopt($ch, CURLOPT_HEADER, false);
    // 返回参数
    curl_exec($ch);
    // 获取头
    $data = curl_getinfo($ch);
    // 销毁
    curl_close($ch); 
    if($data['redirect_url'] == ''){
        $data['redirect_url'] = $data['url'];
    }
    return $data['redirect_url'];
}
return;
        $header = get_headers($url,1);
        if (strpos($header[0],'301') || strpos($header[0],'302'))
        {
            if(is_array($header['Location']))
            {
                //return $header['Location'][count($header['Location'])-1];
                return $header['Location'][0];
            }
            else
            {
                return $header['Location'];
            }
        }
        else
        {
            return $url;
        }
    }

}
