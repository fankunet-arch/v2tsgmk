<?php
/**
 * Toptea Store - POS 帮助库 (Bootstrapper)
 * 加载所有 POS API 需要的业务逻辑函数库。
 * Version: 1.0.0
 * Date: 2025-11-08
 *
 * [A2 UTC SYNC]: Added pos_datetime_helper.php
 * [B1.2 PASS]: Added pos_pass_helper.php
 */

// [A2 UTC SYNC] 引入新的时间助手
require_once realpath(__DIR__ . '/pos_datetime_helper.php');

// [B1.2 PASS] 引入次卡业务助手
require_once realpath(__DIR__ . '/pos_pass_helper.php');

// 核心业务逻辑 (门店配置, 购物车, 班次计算等)
// 
require_once realpath(__DIR__ . '/pos_repo.php');

// 促销引擎
require_once realpath(__DIR__ . '/../services/PromotionEngine.php');

// 票据合规守卫
require_once realpath(__DIR__ . '/../core/invoicing_guard.php');

// 班次守卫
require_once realpath(__DIR__ . '/../core/shift_guard.php');
}