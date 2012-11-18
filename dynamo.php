<?php

class validationException extends Exception {
     
     public function __construct($message,$code, Exception $previous = null){
          parent::__construct($message, $code, $previous);
     }
}

/*
 * Name: dynamo
 * Description: Dynamic object.  Can take anonymous functions as methods with access to $this.
 *        Contains validation information for the table it spawned from
 * 
 */
class dynamo implements Iterator{
     private $properties = [];
     private $meta = [];

     /*
      * Name: __construct
      * Description:  Constructor for dynamo
      * Takes: values = ['property'=>'value']
      */
     public function __construct($values = []){
          foreach($values as $name=>$value){
               $this->properties[$name]=$value;
          }
     }
     
     /*
      * Name: __set
      * Description: Sets a property for the dynamic object.  Verifies the incoming value against the table validation rules which
      * the dynamo is aware of.  Gently fails if value outside valid range.  Determines if incoming propertry is a method call and
      * if so, binds it to $this.  strings attempt to change incoming values into strings so any reasonable value can be sent to string
      */
     public function __set($name, $value){
          try {
               if(is_callable($value)){
                    $this->$name = $value->bindTo($this); //bindTo($this) grants the function access to $this
               }
               else {
                    if(isset($this->meta[$name])){
                         if(!array_key_exists('fixed',$this->meta)){
                              if($this->meta[$name]['type'] ==="numeric"){
                                   if(abs($value)<=$this->meta[$name]['max'] && $value >= $this->meta[$name]['max'] * -1){
                                        $this->properties[$name] = $value;
                                   }
                                   else {
                                        throw new validationException("$value falls outside of $name available range", 1);
                                   }
                              }
                              elseif($this->meta[$name]['type'] === "string"){
                                   if(array_key_exists("length",$this->meta[$name])){
                                        $value = (string)$value;
                                        if(strlen($value) <= $this->meta[$name]['length']){
                                             $this->properties[$name] = $value;
                                        }
                                        else {
                                             throw new validationException("$value has too many characters for $name",2);
                                        }
                                   }
                                   else {
                                        $value = (string)$value;
                                        $this->properties[$name] = $value;
                                   }
                              }
                              elseif($this->meta[$name]['type'] === "boolean"){
                                   if(is_bool($value)){
                                        $this->properties[$name] = $value;
                                   }
                                   else {
                                        throw new validationException("$name expectes boolean value; not $value",3);
                                   }
                              }
                              elseif($this->meta[$name]['type'] === "date"){
                                   if(get_class($value) === "DateTime"){
                                        if(isset($this->meta[$name]['format'])){
                                             $value->format($this->meta[$name]['format']);
                                        }
                                        $this->properties[$name] = $value;
                                   }
                                   else {
                                        throw new validationException("$value not a date for $name",4);
                                   }
                              }
                         }
                         else {
                              throw new validationException("$name is fixed and cannot be changed to $value",5);
                              a:
                         }
                    }
                    else {
                         $this->properties[$name] = $value;
                    }
               }
          }
          catch (validationException $e){
               echo $e->getMessage();
               goto a;
          }
     }
     
     /* Name: __get
      * Description:  Returns the set property which belongs to the dynamo or returns an error if the property is unset using traditional
      * undefined property message/method
      */
     public function __get($name){
          if(array_key_exists($name, $this->properties)){
               return($this->properties[$name]);
          }
          else {
               $trace = debug_backtrace();
               trigger_error(
                   'Undefined property via __get(): ' . $name .
                   ' in ' . $trace[0]['file'] .
                   ' on line ' . $trace[0]['line'],
                   E_USER_NOTICE);
               return null;
          }
     }
     
     /* Name: __isset
      * Description:  Determines whether a property exists within the object
      */
     public function __isset($name){
          if(array_key_exists($name, $this->properties)){
               return(true);
          }
          else {
               return(false);
          }
     }
     
     /* Name: __unset
      * Description:  Removes a property from the object and any validation information for that property
      */
     public function __unset($name){
          unset($this->properties[$name]);
          if(array_key_exists($name, $this->meta)){
               unset($this->meta);
          }
     }
     
     /* Name: __call
      * Description:  calls a method name attached to this object with the supplied arguments
      */
     public function __call($method, $args){
          try {
               if(isset($this->$method)){
                    if(is_callable($this->$method)){
                         $func = $this->$method;
                         $func($args);
                    }
                    else {
                         throw new BadMethodCallException("$method is not a callable function!");
                    }
                    
               }
               else {
                    throw new BadMethodCallException("$method is not set!");
               }
          }
          catch (BadMethodCallException $e){
               echo $e->getMessage();
          }
          catch (Exception $e){
               echo $e->getMessage();
          }
     }
     
     /* Name: __toString
      * Description:  Outputs the object as a json_encoded string
      */
     public function __toString(){
          return(json_encode($this->properties));
     }
     
     /* Name: rewind
      * Description:  Interator required function, returns property list to first index
      */
     public function rewind(){
          reset($this->properties);
     }
     
     /* Name: rewind
      * Description:  Interator required function, returns current property in property list
      */
     public function current(){
          return(current($this->properties));
     }
     
     /* Name: key
      * Description:  Interator required function, returns key of current property 
      */
     public function key(){
          return(key($this->properties));
     }
     
     /* Name: next
      * Description:  Interator required function, moves property list to next index
      */
     public function next(){
          return(next($this->properties));
     }
     
     /* Name: valid
      * Description:  Interator required function, returns whether the next key in the properties is not null
      */
     public function valid(){
          return(key($this->properties) !== null);
     }
     
     /* Name: setValidationRules 
      * Description:  Sets the metadata for the properties of the object.  This meta data should represent the values which the
      *        property can safely take.  For example:  if the mysql database entry which this dynamo represents has a max length of 11 for a
      *        varchar field, the metadata should have a 'length' value which represents that limitation (11).
      * Takes: vRules - Associative array of validation rules.  [type=>"", length=>"", default=>"", "primaryKey"=>true, "auto" (autonumbering)=>true]
      *        if primaryKey and auto are true, the field is set to 'fixed' meaning it cannot be changed or it will throw a validation error
      *        if the type is numeric, the length field is changed to a max value representation. (length of 1 = max values of 9 and -9)
      */
     
     public function setValidationRules($vRules = []){
          foreach($vRules as $var=>$rules){
               if(array_key_exists($var,$this->properties)){
                    $this->meta[$var] = [];
                    switch($rules['type']){  //sets validation type (numeric, boolean, string or date)
                         case "int":
                         case "decimal":
                         case "double":
                         case "float":
                         case "real":
                         case "bit":
                         case "serial":
                              $this->meta[$var]['type'] = 'numeric';
                              $this->meta[$var]['max'] = pow(10, $rules['length'])-1;
                              break;
                         case "bool":
                              $this->meta[$var]['type'] = 'boolean';
                              break;
                         case "date":
                         case "time":
                         case "year":
                              $this->meta[$var]['type']='date';
                              $this->meta[$var]['format'] = $rules['format'];
                              break;
                         default:
                              $this->meta[$var]['type']='string';
                              if(array_key_exists('length',$rules)){
                                   $this->meta[$var]['length'] = $rules['length'];
                              }
                              break;
                    }
                    $this->meta[$var]['default'] = $rules['default'];
                    if(isset($rules['primaryKey']) && isset($rules['auto'])){
                         $this->meta[$var]['fixed'] = true;
                    }
               }
          }
     }
     
     /* Name: getRule
      * Description:  Returns the validation rules of a property
      * Takes:  property name
      */
     public function getRule($key){
          if(isset($this->meta[$key])){
               return($this->meta[$key]);
          }
          else {
               return(false);
          }
     }
     
     /* Name: getRules
      * Description:  Returns all the validation rules for the object
      */
     public function getRules(){
          return($this->meta);
     }
     
     /* Name: unsetRule
      * Description:  Destroys the validation rules for a property
      * Takes: property name
      */
     public function unsetRule($key){
          unset($this->meta[$key]);
     }
     
     /* Name: unsetRules
      * Description:  nullifies all validation rules in the dynamic object (but retains the meta array so new validation rules can still be applied)
      */
     public function unsetRules(){
          $this->meta = [];
     }
}
?>