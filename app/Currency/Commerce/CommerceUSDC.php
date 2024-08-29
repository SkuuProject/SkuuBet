<?php namespace App\Currency\Commerce;

class CommerceUSDC extends CommerceCurrency {

  function id(): string {
    return "commerce_usdc";
  }

  public function walletId(): string {
    return "usdc";
  }

  function name(): string {
    return "USDC (ERC20)";
  }

  public function alias(): string {
    return "usdc";
  }

  public function displayName(): string {
    return "USDC";
  }

  function icon(): string {
    return "usdc";
  }

  public function style(): string {
    return "#627eea";
  }

  public function coinbaseId(): string {
    return 'USDC';
  }

  public function coinbaseName(): string {
    return 'usdc';
  }

  public function coinpaymentsId(): string {
    return 'USDC';
  }
}
