<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Models\Node;
use App\Models\User;
use App\Utils\Tools;
use App\Utils\URL;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 *  User NodeController
 */
final class NodeController extends BaseController
{
    /**
     * @param array     $args
     */
    public function userNodePage(Request $request, Response $response, array $args): ResponseInterface
    {
        $user = $this->user;
        $query = Node::query();
        $query->where('type', 1)->whereNotIn('sort', [9]);
        if (! $user->is_admin) {
            $group = ($user->node_group !== 0 ? [0, $user->node_group] : [0]);
            $query->whereIn('node_group', $group);
        }
        $nodes = $query->orderBy('node_class')->orderBy('name')->get();
        $all_node = [];
        foreach ($nodes as $node) {
            /** @var Node $node */

            $array_node = [];
            $array_node['id'] = $node->id;
            $array_node['name'] = $node->name;
            $array_node['class'] = $node->node_class;
            $array_node['info'] = $node->info;
            $array_node['flag'] = $node->getNodeFlag();
            $array_node['online_user'] = $node->getNodeOnlineUserCount();
            $array_node['online'] = $node->getNodeOnlineStatus();
            $array_node['latest_load'] = $node->getNodeLatestLoadText();
            $array_node['traffic_rate'] = $node->traffic_rate;
            $array_node['status'] = $node->status;
            $array_node['traffic_used'] = (int) Tools::flowToGB($node->node_bandwidth);
            $array_node['traffic_limit'] = (int) Tools::flowToGB($node->node_bandwidth_limit);
            $array_node['bandwidth'] = $node->getNodeSpeedlimit();

            $all_connect = [];
            if (in_array($node->sort, [0])) {
                if ($node->mu_only !== 1) {
                    $all_connect[] = 0;
                }
                if ($node->mu_only !== -1) {
                    $mu_node_query = Node::query();
                    $mu_node_query->where('sort', 9)->where('type', '1');
                    if (! $user->is_admin) {
                        $mu_node_query->where('node_class', '<=', $user->class)->whereIn('node_group', $group);
                    }
                    $mu_nodes = $mu_node_query->get();
                    foreach ($mu_nodes as $mu_node) {
                        if (User::where('port', $mu_node->server)->where('is_multi_user', '<>', 0)->first() !== null) {
                            $all_connect[] = $node->getOffsetPort($mu_node->server);
                        }
                    }
                }
            } else {
                $all_connect[] = 0;
            }
            $array_node['connect'] = $all_connect;

            $all_node[$node->node_class + 1000][] = $array_node;
        }

        return $response->write(
            $this->view()
                ->assign('nodes', $all_node)
                ->display('user/node/index.tpl')
        );
    }

    /**
     * @param array     $args
     */
    public function userNodeAjax(Request $request, Response $response, array $args): ResponseInterface
    {
        $id = $args['id'];
        $point_node = Node::find($id);
        $prefix = explode(' - ', $point_node->name);
        return $response->write(
            $this->view()
                ->assign('point_node', $point_node)
                ->assign('prefix', $prefix[0])
                ->assign('id', $id)
                ->display('user/node/nodeajax.tpl')
        );
    }

    /**
     * @param array     $args
     */
    public function userNodeInfo(Request $request, Response $response, array $args): ResponseInterface
    {
        $user = $this->user;
        $node = Node::find($args['id']);
        if ($node === null) {
            return $response->write('????????????');
        }
        if (! $user->is_admin) {
            if ($user->node_group !== $node->node_group && $node->node_group !== 0) {
                return $response->write('??????????????????????????????');
            }
            if ($user->class < $node->node_class) {
                return $response->write('??????????????????????????????');
            }
        }
        switch ($node->sort) {
            case 0:
                return $response->write(
                    $this->view()
                        ->assign('node', $node)
                        ->assign('mu', $request->getQueryParams()['ismu'])
                        ->registerClass('URL', URL::class)
                        ->display('user/node/node_ss_ssr.tpl')
                );
            case 11:
                $server = $node->getV2RayItem($user);
                $nodes = [
                    'url' => URL::getV2Url($user, $node),
                    'info' => [
                        '???????????????' => $server['add'],
                        '???????????????' => $server['port'],
                        'UUID???' => $user->uuid,
                        'AlterID???' => $server['aid'],
                        '???????????????' => $server['net'],
                    ],
                ];
                if ($server['net'] === 'ws') {
                    $nodes['info']['PATH???'] = $server['path'];
                    $nodes['info']['HOST???'] = $server['host'];
                }
                if ($server['net'] === 'kcp') {
                    $nodes['info']['???????????????'] = $server['type'];
                }
                if ($server['tls'] === 'tls') {
                    $nodes['info']['TLS???'] = 'TLS';
                }
                return $response->write(
                    $this->view()
                        ->assign('node', $nodes)
                        ->display('user/node/node_v2ray.tpl')
                );
            case 13:
                $server = $node->getV2RayPluginItem($user);
                if ($server !== null) {
                    $nodes = [
                        'url' => URL::getItemUrl($server, 1),
                        'info' => [
                            '???????????????' => $server['address'],
                            '???????????????' => $server['port'],
                            '???????????????' => $server['method'],
                            '???????????????' => $server['passwd'],
                            '???????????????' => $server['obfs'],
                            '???????????????' => $server['obfs_param'],
                        ],
                    ];
                } else {
                    $nodes = [
                        'url' => '',
                        'info' => [
                            '????????????????????? AEAD ??????' => '?????????????????????.',
                        ],
                    ];
                }
                return $response->write(
                    $this->view()
                        ->assign('node', $nodes)
                        ->display('user/node/node_ss_v2ray_plugin.tpl')
                );
            case 14:
                $server = $node->getTrojanItem($user);
                $nodes = [
                    'url' => URL::getTrojanUrl($user, $node),
                    'info' => [
                        '???????????????' => $server['address'],
                        '???????????????' => $server['port'],
                        '???????????????' => $server['passwd'],
                    ],
                ];
                if ($server['host'] !== $server['address']) {
                    $nodes['info']['HOST&PEER???'] = $server['host'];
                }
                return $response->write(
                    $this->view()
                        ->assign('node', $nodes)
                        ->display('user/node/node_trojan.tpl')
                );
            default:
                return $response->write(404);
        }
    }
}
