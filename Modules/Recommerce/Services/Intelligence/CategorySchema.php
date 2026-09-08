<?php
namespace Modules\Recommerce\Services\Intelligence;
use LogicException;
final class CategorySchema {
 public static function fields(string $category): array {
  if(!in_array($category,['LAPTOP','PHONE','TABLET'],true))throw new LogicException('Unsupported trade-in category.');
  return $category==='LAPTOP'?['processor','ram','storage']:($category==='TABLET'?['storage','connectivity']:['storage']);
 }
 public static function functions(string $category,array $spec=[]): array {
  $base=['charging','camera','wifi','repair_history','liquid_damage'];
  if($category==='LAPTOP')return array_merge($base,['keyboard','trackpad','ports']);
  $base=array_merge($base,['buttons','body','activation_lock']);
  if($category==='PHONE')$base=array_merge($base,['biometrics','network']);
  if($category==='TABLET'&&($spec['connectivity']??'')==='cellular')$base[]='network';
  return $base;
 }
 public static function validate(array $device,array $condition): void {
  $category=$device['category'];self::fields($category);
  $enums=['power'=>['yes','intermittent','no','not_sure'],'screen'=>['perfect','minor','lines','cracked','not_working','not_sure'],'battery'=>['good','short','plugged','missing','not_sure'],'physical'=>['excellent','good','fair','poor']];
  foreach($enums as $f=>$values)if(!in_array($condition[$f]??null,$values,true))throw new LogicException('Choose a valid '.$f.' answer.');
  foreach($condition as $f=>$v)if(!is_string($v)||strlen($v)>500)throw new LogicException('Invalid condition answer.');
  foreach(self::functions($category,$device['configuration']) as $f)if(isset($condition[$f])&&!in_array($condition[$f],['working','fault','not_sure','not_applicable','yes','no'],true))throw new LogicException('Choose a valid '.$f.' answer.');
  foreach($device['configuration'] as $v)if(!is_string($v)||strlen($v)>150)throw new LogicException('Invalid specification.');
 }
 public static function exact(array $variant,array $device): bool {
  if(($variant['category']??null)!==$device['category']||($variant['model_id']??null)!==$device['model_id'])return false;
  foreach(self::fields($device['category']) as $f)if(empty($variant['specification'][$f])||in_array($variant['specification'][$f],['not_sure','not_applicable'],true)||($device['configuration'][$f]??null)!==$variant['specification'][$f])return false;
  return true;
 }
}
