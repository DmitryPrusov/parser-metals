<?php
namespace backend\components;

use backend\models\AveragePrice;
use backend\models\BullionType;
use Faker\Provider\DateTime;
use keltstr\simplehtmldom\simple_html_dom;
use keltstr\simplehtmldom\SimpleHTMLDom as SHD;
use yii\base\ErrorException;

/**
 * Class for parsing of the average price per metal for a current Year
 */
class PriceParserHelper
{

    const PARSE_CURRENCY = 'EUR';

    private $url = 'http://lbma.oblive.co.uk/table';

    private $requestType = 'daily';

    private $year;

    private $htmlTable = 'table.data';


    public function __construct()
    {
        $this->year = date('Y');
    }


    public function setYear($newYear)
    {
        $this->year = $newYear;
    }

    public function getRequestUrl($metal)
    {
        $queryParams = [
            'metal' => $metal,
            'year'  => date('Y'),
            'type'  => $this->requestType
        ];
        return $this->url . '?' . http_build_query($queryParams);
    }


    public function getYearlyData($html)
    {
        $parsedData = [];
        $tableCollection = $html->find($this->htmlTable);
        $colspan = 0;
        $subCells = 1;
        if (!empty($tableCollection) && count($tableCollection) == 1) {
            $table = reset($tableCollection);
            $theadCells = $table->find('tr.currency th');

            foreach ($theadCells as $key => $cell) {
                $colspanCounter = 1;
                if (!empty($cell->colspan)) {
                    $colspanCounter = $cell->colspan;
                }
                if ($cell->plaintext == self::PARSE_CURRENCY) {
                    $subCells = $colspanCounter;
                    break;
                } else {
                    $colspan += $colspanCounter;
                }
            }

            foreach ($table->find('tbody tr') as $row) {
                $currencyData = [];

                $date_cell = $row->children(0);
                $date = date("Y-m-d", strtotime($date_cell->plaintext));

                for ($i = 0; $i < $subCells; $i++) {
                    $cell = $row->children($i + $colspan);
                    if (!empty($cell->plaintext)) {
                        $currencyData[] = $cell->plaintext;
                    }
                }
                if (!empty($currencyData)) {

                    $amount = array_sum($currencyData) / count($currencyData);

                    $requiredData = array('date' => $date, 'amount' => $amount);
                    array_push($parsedData, $requiredData);
                }
            }
            unset($parsedData[0]);
        }
        return $parsedData;
    }

    public function runYearly()
    {
        $metals = BullionType::getMetalParseSlugs();

        foreach ($metals as $slug => $title) {
            $parsedData[$slug] = $this->getParsedData($slug);

            foreach ($parsedData[$slug] as $key => $dailyData) {
                $searchData = [
                    'date_created' => $dailyData['date'],
                    'metal'        => $slug
                ];
                $averagePrice = AveragePrice::find()->where($searchData)->one();
                if (empty($averagePrice)) {

                    $averagePrice = new AveragePrice();
                    $averagePrice->metal = $slug;
                    $averagePrice->amount = $dailyData['amount'];
                    $averagePrice->date_created = $dailyData['date'];
                }
                $averagePrice->save(true, null, true);
            }
        }
    }

    public function getParsedData($metal)
    {
        try {
            $html = SHD::file_get_html($this->getRequestUrl($metal));
            $parsedData = $this->getYearlyData($html);
        } catch (ErrorException $error) {
            $parsedData = false;
        }

        return $parsedData;
    }

}