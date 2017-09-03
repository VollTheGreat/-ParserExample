<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ParsedData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Goutte\Client;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\DomCrawler\Crawler;

class ParserController extends Controller
{

    private $dataPath;
    private $parserSettings;
    private $getBranches;

    public function __construct()
    {
        $this->dataPath = public_path('data.csv');
        $this->parserSettings = [
            'timeout'     => 10,
            'delay'       => rand(3000, 4000),
            'http_errors' => false,
        ];
        $this->getBranches = true;
    }

    /**
     * start parsing page
     * @return array
     */
    public function start()
    {
        $pawnshopsIds = $this->getBranchesFromFile($this->csvToArray($this->dataPath, ';'));
        $checkData = ParsedData::all();
        $checkData = $checkData->map(function ($item) {
            return $item['IM_NUMIDENT'];
        });

        $this->getSiteData($pawnshopsIds->diff($checkData));

        return [
            'Зарисів у файлі'     => count($pawnshopsIds),
            'Всього записів'      => count($checkData),
            'Нових\Не оброблених' => count($pawnshopsIds->diff($checkData)),
        ];
    }
    /**
     * take organization id from csv file
     * @param Collection $dataArray
     * @return Collection
     */
    private function getBranchesFromFile($dataArray)
    {
        return $dataArray->filter(function ($node) {
            return $node['Тип установи'] == 'Ломбард';
        })->map(function ($node) {
            return $node['Код за ЄДРПОУ'];
        });

    }
    /**
     * Open and parse csv file for further work
     * @param string $filename
     * @param string $delimiter
     * @return Collection|bool
     */
    private function csvToArray($filename = '', $delimiter = ',')
    {
        function utf8_fopen_read($fileName)
        {
            $fc = iconv('windows-1251', 'utf-8', file_get_contents($fileName));
            $handle = fopen("php://memory", "rw");
            fwrite($handle, $fc);
            fseek($handle, 0);

            return $handle;
        }

        if (!file_exists($filename) || !is_readable($filename)) {
            return false;
        }
        $header = null;
        $data = collect([]);
        if (($handle = utf8_fopen_read($filename)) !== false) {
            while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
                if (!$header) {
                    $header = $row;
                } else {
                    $data[] = array_combine($header, $row);
                }
            }
            fclose($handle);
        }

        return $data;
    }
    /**
     * main parsing body
     * @param $ids
     * @param int $type
     * @return Collection|null
     */
    private function getSiteData($ids, $type = 6)
    {
        $client = new Client();

        $guzzleClient = new GuzzleClient($this->parserSettings);
        $client->setClient($guzzleClient);

        $crawler = $client->request('GET', 'http://www.kis.nfp.gov.ua');
        $_viewState = $crawler->filter('#__VIEWSTATE')->attr('value');
        $_viewStateGenerate = $crawler->filter('#__VIEWSTATEGENERATOR')->attr('value');
        $form = $crawler->selectButton('Пошук')->form();

        $pawnshopsInfo = collect([]);
        foreach ($ids as $pawnshopCode) {
            $siteData = $client->submit($form, [
                'p_EDRPOU'             => $pawnshopCode,
                'p_IRL_FT'             => $type,
                '__VIEWSTATE'          => $_viewState,
                '__VIEWSTATEGENERATOR' => $_viewStateGenerate,
            ]);
            if ($siteData) {
                $pawnshopsInfo = $this->getPawnshop($siteData);
                $this->saveDataToDB($pawnshopsInfo);
            }
        }

        return $pawnshopsInfo;
    }
    /**
     * searching Pawnshops, and optional branches
     * @param Crawler $crawler
     * @return Collection|null
     */
    private function getPawnshop(Crawler $crawler)
    {
        $pawnshopData = collect([]);
        $shop = $crawler->filter('.t11ReportsRegion100Width .grid td');

        if ($shop) {
            $shop->each(function (Crawler $node) use (&$pawnshopData) {
                if ($node->filter('a')->count()) {
                    $pawnshopData[$node->attr('headers')] = 'http://www.kis.nfp.gov.ua'.$node->filter('a')->attr('href');

                    return;
                }
                //Якщо філал без ссилки
                if ($node->attr('headers') == 'FILIALS' & !$node->filter('a')->count()) {
                    $pawnshopData[$node->attr('headers')] = null;

                    return;
                }
                $pawnshopData[$node->attr('headers')] = $node->text();
            });

            if ($this->isBranches($pawnshopData) && $this->getBranches == true) {
                $branch_url = $this->getBranchFromPawnshop($pawnshopData);
                $pawnshopData['filials_data'] = $this->getPawnshopBranch($branch_url);
            }

            return $pawnshopData;
        }

        return null;
    }
    /**
     * is Branch available on page
     * @param Collection $pawnshopData
     * @return bool
     */
    private function isBranches($pawnshopData)
    {
        if (!empty($this->getBranchFromPawnshop($pawnshopData))) {
            return true;
        }

        return false;
    }
    /**
     * get Branch Url from pawnshop page
     * @param Collection $pawnshopData
     * @return string
     */
    private function getBranchFromPawnshop($pawnshopData)
    {
        return $pawnshopData->get('FILIALS');
    }
    /**
     * getting branch data
     * @param string $url
     * @return Collection|null
     */
    private function getPawnshopBranch($url)
    {
        $client = new Client();
        $guzzleClient = new GuzzleClient($this->parserSettings);
        $client->setClient($guzzleClient);

        $crawler = $client->request('GET', $url);
        $branches = collect([]);
        $getBranches = $crawler->filter('.zebra tr');
        if ($getBranches) {
            $getBranches->each(function (Crawler $node, $i) use (&$branches, &$branchesTableColumnNames) {
                if ($i === 0) {
                    $node->filter('th')->each(function (Crawler $node, $i) use (&$branchesTableColumnNames) {
                        $branchesTableColumnNames[$i] = $node->attr('id');
                    });

                    return;
                }
                $branchItem = [];

                $node->filter('td')->each(function (Crawler $node, $i) use (&$branchItem, $branchesTableColumnNames) {
                    if ($node->filter('a')->count()) {
                        $branchItem[$branchesTableColumnNames[$i]] = 'http://www.kis.nfp.gov.ua'.$node->filter('a')->attr('href');

                        return;
                    }
                    $branchItem[$branchesTableColumnNames[$i]] = $node->text();
                });

                $branches[] = $branchItem;
            });

            return $branches;
        }

        return null;
    }
    /**
     * saving result to database
     * @param Collection $data
     */
    private function saveDataToDB($data)
    {
        $newPawnshopData = new ParsedData();
        foreach ($data as $key => $value) {
            if ($key == 'filials_data') {
                break;
            }
            if ($key == 'IA_PHONE_CODE') {
                $newPawnshopData->IA_PHONE_CODE = preg_replace("/[^0-9]/", "", $value);
            } elseIf ($key == 'IA_PHONE') {
                //dd(preg_replace("/[^0-9]/", "", $value));
                $newPawnshopData->IA_PHONE = preg_replace("/[^0-9]/", "", $value);
            } elseIf ($key == 'IM_NUMIDENT') {
                $newPawnshopData->IM_NUMIDENT = preg_replace("/[^0-9]/", "", $value);
            } else {
                $newPawnshopData->{$key} = $value;
            }
        }
        $newPawnshopData->parsed_at = Carbon::now();
        $newPawnshopData->save();
        if ($data['FILIALS'] != null) {
            foreach ($data['filials_data'] as $branch) {
                $newBranchData = new ParsedData();
                foreach ($branch as $child_key => $child_value) {
                    if ($child_key == 'IM_NUMIDENT') {
                        $newBranchData->IAN_FULL_NAME = $child_value;
                    } elseIf ($child_key == 'IAN_FULL_NAME') {
                        $newBranchData->IM_NUMIDENT = preg_replace("/[^0-9]/", "", $child_value);
                    } elseIf ($child_key == 'IA_PHONE_CODE') {
                        $newBranchData->IA_PHONE_CODE = preg_replace("/[^0-9]/", "", $child_value);
                    } elseIf ($child_key == 'IA_PHONE') {
                        $newBranchData->IA_PHONE = preg_replace("/[^0-9]/", "", $child_value);
                    } else {
                        $newBranchData->{$child_key} = $child_value;
                    }
                }
                $newBranchData->parsed_at = Carbon::now();
                $newPawnshopData->filials_data()->save($newBranchData);

            }
        }
    }
}
