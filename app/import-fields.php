<?php
function import_date_value(string $value): ?string
{
    $value=trim($value);if($value==='')return null;
    if(valid_date($value))return $value;
    if(ctype_digit($value)&&(int)$value>=61&&(int)$value<=100000)return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int)$value.' days')->format('Y-m-d');
    throw new InvalidArgumentException('Use ISO dates YYYY-MM-DD or Excel date serials.');
}
function import_amount_value(string $value): ?float
{
    if(trim($value)==='')return null;
    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>100000)throw new InvalidArgumentException('Invalid imported rate.');
    return (float)$value;
}
