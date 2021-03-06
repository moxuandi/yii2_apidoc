<?php

namespace Route\models;
use Yii;

/**
 * Class Route
 * @package app\modules\route\models
 */
class Route extends \yii\base\Object
{

    public $ignore = [];
    public function getRoutes($module = null)
    {
        return $this->getAppRoutes($module);
    }

    public function getAppRoutes($module = null)
    {

        if ($module === null) {
            $module = Yii::$app;
        } elseif (is_string($module)) {
            $module = Yii::$app->getModule($module);
        }
        $result = [];
        $this->getRouteRecrusive($module, $result);
        return $result;
    }

    protected function getRouteRecrusive($module, &$result)
    {
        $token = "Get Route of '" . get_class($module) . "' with id '" . $module->uniqueId . "'";
        Yii::beginProfile($token, __METHOD__);
        try {
            foreach ($module->getModules() as $id => $child) {
                if (($child = $module->getModule($id)) !== null) {
                    $this->getRouteRecrusive($child, $result);
                }
            }

            foreach ($module->controllerMap as $id => $type) {
                $this->getControllerActions($type, $id, $module, $result);
            }

            $namespace = trim($module->controllerNamespace, '\\') . '\\';
            $this->getControllerFiles($module, $namespace, '', $result);
            $all = '/' . ltrim($module->uniqueId . '/*', '/');
            $result[$all] = $all;
        } catch (\Exception $exc) {
            Yii::error($exc->getMessage(), __METHOD__);
        }
        Yii::endProfile($token, __METHOD__);
    }

    protected function getControllerFiles($module, $namespace, $prefix, &$result)
    {
        $path = Yii::getAlias('@' . str_replace('\\', '/', $namespace), false);
        $token = "Get controllers from '$path'";
        Yii::beginProfile($token, __METHOD__);
        try {
            if (!is_dir($path)) {
                return;
            }
            foreach (scandir($path) as $file) {
                if ($file == '.' || $file == '..') {
                    continue;
                }
                if (is_dir($path . '/' . $file) && preg_match('%^[a-z0-9_/]+$%i', $file . '/')) {
                    $this->getControllerFiles($module, $namespace . $file . '\\', $prefix . $file . '/', $result);
                } elseif (strcmp(substr($file, -14), 'Controller.php') === 0) {
                    $baseName = substr(basename($file), 0, -14);
                    $name = strtolower(preg_replace('/(?<![A-Z])[A-Z]/', ' \0', $baseName));
                    $id = ltrim(str_replace(' ', '-', $name), '-');
                    $className = $namespace . $baseName . 'Controller';
                    if (strpos($className, '-') === false && class_exists($className) && is_subclass_of($className, 'yii\base\Controller')) {
                        $this->getControllerActions($className, $prefix . $id, $module, $result);
                    }
                }
            }
        } catch (\Exception $exc) {
            Yii::error($exc->getMessage(), __METHOD__);
        }
        Yii::endProfile($token, __METHOD__);
    }

    protected function getControllerActions($type, $id, $module, &$result)
    {
        if(in_array($id,$this->ignore)) return;
        $controller = Yii::createObject($type, [$id, $module]);
        $this->getActionRoutes($controller, $result);
        $all = "/{$controller->uniqueId}/*";
        $result[$all] = $all;
    }

    protected function getActionRoutes($controller, &$result)
    {
        $description = '';
        $descComment = '//请使用@desc 注释';
        $typeMaps = array(
            'string' => '字符串',
            'int' => '整型',
            'float' => '浮点型',
            'boolean' => '布尔型',
            'date' => '日期',
            'array' => '数组',
            'fixed' => '固定值',
            'enum' => '枚举类型',
            'object' => '对象',
        );
        $token = "Get actions of controller '" . $controller->uniqueId . "'";
        Yii::beginProfile($token, __METHOD__);
        try {
            $prefix = '/' . $controller->uniqueId . '/';
            foreach ($controller->actions() as $id => $value) {
                //$result[$prefix . $id] = $prefix . $id;
            }
            $class = new \ReflectionClass($controller);
            foreach ($class->getMethods() as $method) {
                $name = $method->getName();
                if ($method->isPublic() && !$method->isStatic() && strpos($name, 'action') === 0 && $name !== 'actions') {
                    $name = strtolower(preg_replace('/(?<![A-Z])[A-Z]/', ' \0', substr($name, 6)));
                    $id = $prefix . ltrim(str_replace(' ', '-', $name), '-');
                    //$result[$id] = $id;
                    $result[$id] = [
                        'id' => $id,
                        'description' => '',
                        'descComment' => '//请使用@desc 注释',
                        'methodComment'=>"",
                        'request' => [],
                        'response' => [],
                    ];
                    $docComment = $method->getDocComment();
                    $docCommentArr = explode("\n", $docComment);
                    foreach ($docCommentArr as $comment) {
                        $comment = trim($comment);

                        //标题描述
                        if (empty($result[$id]['description']) && strpos($comment, '@') === false && strpos($comment, '/') === false) {
                            $result[$id]['description'] = (string)substr($comment, strpos($comment, '*') + 1);
                            continue;
                        }

                        //@desc注释
                        $pos = stripos($comment, '@desc');
                        if ($pos !== false) {
                            $result[$id]['descComment'] = substr($comment, $pos + 5);
                            continue;
                        }

                        //@method注释
                        $pos = stripos($comment, '@method');
                        if ($pos !== false) {
                            $result[$id]['methodComment'] = substr($comment, $pos + 7);
                            continue;
                        }

                        //@param注释
                        $pos = stripos($comment, '@param');
                        if ($pos !== false) {
                            $params = [
                                'name' => '',
                                'type' => '',
                                'require' => true,
                                'default' => '',
                                'other' => '',
                                'desc' => ''
                            ];
                            $paramCommentArr = explode(' ', substr($comment, $pos + 7));
                            if (preg_match('/\$[A-Z0-9]*/', @$paramCommentArr[1])) {
                                $params['name'] = substr($paramCommentArr[1], 1);
                                $params['type'] = $paramCommentArr[0];
                                foreach ($paramCommentArr as $k => $v) {
                                    if ($k < 2) {
                                        continue;
                                    }
                                    $params['desc'] .= $v;
                                }
                                foreach ($method->getParameters() as $item) {
                                    if ($item->getName() !== $params['name']) {
                                        continue;
                                    }
                                    $params['require'] = !$item->isDefaultValueAvailable();
                                    if (!$params['require']) {
                                        $params['default'] = $item->getDefaultValue();
                                    }
                                }
                            }
                            $result[$id]['request'][] = $params;
                            continue;
                        }

                        //@return注释
                        $pos = stripos($comment, '@return');
                        if ($pos === false) {
                            continue;
                        }

                        $returnCommentArr = explode(' ', substr($comment, $pos + 8));
                        //将数组中的空值过滤掉，同时将需要展示的值返回
                        $returnCommentArr = array_values(array_filter($returnCommentArr));
                        if (count($returnCommentArr) < 2) {
                            continue;
                        }
                        if (!isset($returnCommentArr[2])) {
                            $returnCommentArr[2] = '';    //可选的字段说明
                        } else {
                            //兼容处理有空格的注释
                            $returnCommentArr[2] = implode(' ', array_slice($returnCommentArr, 2));
                        }

                        $result[$id]['response'][] = $returnCommentArr;
                    }


                }
            }
        } catch (\Exception $exc) {
            Yii::error($exc->getMessage(), __METHOD__);
        }
        Yii::endProfile($token, __METHOD__);
    }
}
