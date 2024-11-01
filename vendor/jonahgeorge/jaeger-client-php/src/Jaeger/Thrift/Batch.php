<?php
namespace Jaeger\Thrift;

/**
 * Autogenerated by Thrift Compiler (0.11.0)
 *
 * DO NOT EDIT UNLESS YOU ARE SURE THAT YOU KNOW WHAT YOU ARE DOING
 *  @generated
 */
use Thrift\Base\TBase;
use Thrift\Type\TType;
use Thrift\Type\TMessageType;
use Thrift\Exception\TException;
use Thrift\Exception\TProtocolException;
use Thrift\Protocol\TProtocol;
use Thrift\Protocol\TBinaryProtocolAccelerated;
use Thrift\Exception\TApplicationException;


class Batch extends TBase {
  static $isValidate = false;

  static $_TSPEC = array(
    1 => array(
      'var' => 'process',
      'isRequired' => true,
      'type' => TType::STRUCT,
      'class' => '\Jaeger\Thrift\Process',
      ),
    2 => array(
      'var' => 'spans',
      'isRequired' => true,
      'type' => TType::LST,
      'etype' => TType::STRUCT,
      'elem' => array(
        'type' => TType::STRUCT,
        'class' => '\Jaeger\Thrift\Span',
        ),
      ),
    );

  /**
   * @var \Jaeger\Thrift\Process
   */
  public $process = null;
  /**
   * @var \Jaeger\Thrift\Span[]
   */
  public $spans = null;

  public function __construct($vals=null) {
    if (is_array($vals)) {
      parent::__construct(self::$_TSPEC, $vals);
    }
  }

  public function getName() {
    return 'Batch';
  }

  public function read($input)
  {
    return $this->_read('Batch', self::$_TSPEC, $input);
  }

  public function write($output) {
    return $this->_write('Batch', self::$_TSPEC, $output);
  }

}

