import logging
import pandas as pd
import os

logger = logging.getLogger(__name__)


# LOAD DATA
def load_csv_data(folder):

    products = pd.read_csv(folder+r"\products.csv",encoding="utf-8-sig")

    inbound = pd.read_csv(folder+r"\stock_inbound_items_export.csv",encoding="utf-8-sig")

    outbound = pd.read_csv(folder+r"\stock_outbound_items_export.csv", encoding="utf-8-sig")

    suppliers = pd.read_csv(folder+r"\suppliers.csv",encoding="utf-8-sig")

    inbound["created"] = pd.to_datetime(
        inbound["created"],
        format="mixed",
        dayfirst=True
    )

    outbound["created"] = pd.to_datetime(
        outbound["created"],
        format="mixed",
        dayfirst=True
    )


    return products,inbound,outbound,suppliers

# ===============================
# HÀM PHỤ — tính avg_monthly / avg_daily / safety_stock / reorder_point
# từ 1 tập giao dịch xuất bất kỳ (tách ra để tính được 2 lần:
# 1 lần full lịch sử, 1 lần chỉ trước eval_cutoff_year)
# ===============================

def _calc_rop(product_out_subset, min_stock, lead_time):

    if not product_out_subset.empty:

        monthly_export = (
            product_out_subset
            .assign(month=product_out_subset["created"].dt.to_period("M"))
            .groupby("month")["quantity"]
            .sum()
        )

        avg_monthly_export = monthly_export.mean()

    else:
        avg_monthly_export = 0

    avg_daily_export = avg_monthly_export / 30

    safety_stock = int(min_stock * 0.2)

    demand_leadtime = avg_daily_export * lead_time

    reorder_point = demand_leadtime + safety_stock

    return avg_monthly_export, avg_daily_export, safety_stock, reorder_point


# ===============================
# ĐÁNH GIÁ THEO NGÀY - NOT AI
# ===============================

def calculate_notAI_daily(
        products,
        inbound,
        outbound,
        suppliers,
        eval_cutoff_year=2025,
):

    results=[]

    cutoff_ts = pd.Timestamp(f"{eval_cutoff_year}-01-01")

    for product_id in products["id"]:


        product = products[
            products["id"] == product_id
        ].iloc[0]


        min_stock = product["min_stock"]
        max_stock = product["max_stock"]


        supplier_id = product["supplier_id"]


        supplier = suppliers[
            suppliers["id"] == supplier_id
        ]


        if supplier.empty:
            continue


        lead_time = supplier[
            "lead_time_days"
        ].iloc[0]



        product_in = inbound[
            inbound["product_id"] == product_id
        ].copy()


        product_out = outbound[
            outbound["product_id"] == product_id
        ].copy()



        product_in["type"] = "IN"

        product_out["type"] = "OUT"



        transactions = pd.concat(
            [
                product_in[
                    ["created","quantity","type"]
                ],

                product_out[
                    ["created","quantity","type"]
                ]
            ],
            ignore_index=True
        )


        if transactions.empty:
            continue



        transactions = transactions.sort_values(
            "created"
        )


        (
            avg_monthly_export_full,
            avg_daily_export_full,
            safety_stock_full,
            reorder_point_full,
        ) = _calc_rop(product_out, min_stock, lead_time)


        product_out_train = product_out[
            product_out["created"] < cutoff_ts
        ]

        (
            avg_monthly_export_train,
            avg_daily_export_train,
            safety_stock_train,
            reorder_point_train,
        ) = _calc_rop(product_out_train, min_stock, lead_time)


        stock = 0

        daily_history=[]



        for _,row in transactions.iterrows():


            if row["type"]=="IN":

                stock += row["quantity"]


            else:

                stock -= row["quantity"]



            daily_history.append(
                {
                    "date":
                        row["created"].date(),

                    "stock":
                        stock
                }
            )



        daily_history = pd.DataFrame(
            daily_history
        )



        # Tồn kho cuối mỗi ngày CÓ GIAO DỊCH (chưa đủ — còn thiếu các
        # ngày không giao dịch, tồn kho vẫn giữ nguyên chứ không biến mất)
        daily_stock = (
            daily_history
            .groupby("date")
            .last()
            .reset_index()
        )

        # ===========================
        # TRẢI RA TẤT CẢ NGÀY LỊCH (forward-fill)
        # — trước đây chỉ có ~19 dòng/SP/năm (chỉ ngày có giao dịch),
        # khiến "số lần vượt Max" thực chất là "số lần GIAO DỊCH lúc đang
        # vượt Max", bỏ sót toàn bộ các ngày yên lặng ở giữa (tồn kho vẫn
        # đang vượt Max nhưng không có giao dịch nào để "bắt" được).
        # Forward-fill: mỗi ngày không giao dịch lấy tồn kho của lần giao
        # dịch gần nhất trước đó (đúng bản chất — tồn kho không tự đổi
        # nếu không có nhập/xuất) → đếm được đúng SỐ NGÀY LỊCH ở mỗi
        # trạng thái, khớp đúng nghĩa "lịch sử vượt Max" trong đề tài.
        # ===========================
        daily_stock["date"] = pd.to_datetime(daily_stock["date"])
        daily_stock = daily_stock.set_index("date")

        full_range = pd.date_range(
            start=daily_stock.index.min(),
            end=daily_stock.index.max(),
            freq="D",
        )
        daily_stock = daily_stock.reindex(full_range)
        daily_stock["stock"] = daily_stock["stock"].ffill()

        daily_stock = daily_stock.reset_index().rename(columns={"index": "date"})
        daily_stock["date"] = daily_stock["date"].dt.date

        daily_stock["year"] = pd.to_datetime(daily_stock["date"]).dt.year

        # Đếm riêng theo TỪNG NĂM (không gộp chung 2023-2025) — để khớp
        # đúng năm hiển thị trong từng file xuất ra, và để năm 2025 chỉ
        # phản ánh đúng 2025 khi so với AI (đúng nguyên tắc tách năm đã
        # áp dụng cho reorder_point ở trên). Giờ đây đếm theo NGÀY LỊCH
        # thật, không phải theo ngày giao dịch.
        times_below_min_by_year = (
            daily_stock[daily_stock["stock"] < min_stock]
            .groupby("year").size()
        )
        times_above_max_by_year = (
            daily_stock[daily_stock["stock"] > max_stock]
            .groupby("year").size()
        )


        for _,row in daily_stock.iterrows():


            current_stock = row["stock"]

            row_year = pd.to_datetime(row["date"]).year

            if row_year >= eval_cutoff_year:
                avg_monthly_export = avg_monthly_export_train
                avg_daily_export   = avg_daily_export_train
                safety_stock        = safety_stock_train
                reorder_point       = reorder_point_train
            else:
                avg_monthly_export = avg_monthly_export_full
                avg_daily_export   = avg_daily_export_full
                safety_stock        = safety_stock_full
                reorder_point       = reorder_point_full



            if current_stock <= reorder_point:

                status = (
                    "Cảnh báo nhập hàng"
                )


            elif current_stock > max_stock:

                status = (
                    "Tồn kho dư"
                )


            else:

                status = (
                    "Hợp lý"
                )




            results.append(

                {

                "year":
                    row_year,


                "date":
                    row["date"],



                "product_id":
                    product_id,


                "sku":
                    product["sku"],



                "product_name":
                    product["name"],



                "current_stock":
                    current_stock,



                "min_stock":
                    min_stock,


                "max_stock":
                    max_stock,



                "avg_monthly_export":
                    round(
                        avg_monthly_export,
                        2
                    ),



                "avg_daily_export":
                    round(
                        avg_daily_export,
                        2
                    ),
                    "lead_time_days":
                    lead_time,



                "safety_stock":
                    safety_stock,



                "reorder_point":
                    round(
                        reorder_point,
                        2
                    ),

                "times_below_min":
                    int(times_below_min_by_year.get(row_year, 0)),

                "times_above_max":
                    int(times_above_max_by_year.get(row_year, 0)),

                "assessment":
                    status

                }

            )


    return pd.DataFrame(results)


if __name__=="__main__":


    folder = (
        r"C:\xampp\htdocs\smartware"
    )


    products,inbound,outbound,suppliers = load_csv_data(
        folder
    )



    result = calculate_notAI_daily(
        products,
        inbound,
        outbound,
        suppliers
    )



    output_folder = (
        folder+r"\output"
    )


    os.makedirs(
        output_folder,
        exist_ok=True
    )



    for year in result["year"].unique():


        df_year = result[
            result["year"] == year
        ]


        output = (
            output_folder
            +
            f"\\assessment_notAI_{year}.xlsx"
        )


        df_year.to_excel(
            output,
            index=False
        )


        print(
            "Da luu:",
            output
        )